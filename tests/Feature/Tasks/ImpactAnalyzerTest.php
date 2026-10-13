<?php

namespace Tests\Feature\Tasks;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectIndexFile;
use App\Models\ProjectIndexSymbol;
use App\Models\Task;
use App\Tasks\ImpactAnalyzer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Plan §67 — deterministic change-impact analysis against the code index.
 */
class ImpactAnalyzerTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->for(Organization::factory())->create();
        $this->project->forceFill(['index_build_id' => '01JBUILD00000000000000000'])->save();
        $this->project->connection->forceFill(['detected_stack' => ['backend' => ['framework' => 'Laravel', 'version' => '10.48.4']]])->save();

        $this->index([
            'app/Models/Expense.php' => ['php', [['model', 'Expense'], ['function', 'branch']]],
            'app/Http/Controllers/ExpenseController.php' => ['php', [['class', 'ExpenseController'], ['function', 'index']]],
            'app/Policies/ExpensePolicy.php' => ['php', [['class', 'ExpensePolicy']]],
            'routes/web.php' => ['php', [['route', 'GET /expenses']]],
            'database/migrations/2024_01_01_create_expenses_table.php' => ['php', [['table', 'expenses']]],
            'resources/js/pages/expenses/index.tsx' => ['typescript', [['component', 'ExpensesIndex']]],
            'tests/Feature/ExpenseExportTest.php' => ['php', [['test', 'test_admin_can_export_expenses']]],
            'app/Models/Booking.php' => ['php', [['model', 'Booking']]],
        ]);
    }

    /**
     * @param  array<string, array{0: string, 1: list<array{0: string, 1: string}>}>  $files
     */
    private function index(array $files): void
    {
        foreach ($files as $path => [$language, $symbols]) {
            $file = ProjectIndexFile::forceCreate([
                'project_id' => $this->project->id, 'build_id' => $this->project->index_build_id,
                'path' => $path, 'path_hash' => sha1($path), 'language' => $language, 'size' => 4000, 'symbol_count' => count($symbols),
            ]);

            foreach ($symbols as $i => [$kind, $name]) {
                ProjectIndexSymbol::forceCreate([
                    'project_id' => $this->project->id, 'file_id' => $file->id, 'build_id' => $this->project->index_build_id,
                    'kind' => $kind, 'name' => $name, 'line' => $i + 1,
                ]);
            }
        }
    }

    private function analyze(string $title, string $description = ''): array
    {
        $task = Task::factory()->for($this->project)->create(['title' => $title, 'description' => $description]);

        return app(ImpactAnalyzer::class)->analyze($task->load('project.connection'));
    }

    public function test_finds_the_files_tests_and_areas_a_request_touches()
    {
        $impact = $this->analyze(
            'Add expenses export to Excel',
            "Only Admin and Super Admin can access it.\nBranch Managers must remain read-only.",
        );

        $paths = array_column($impact['expected_files'], 'path');
        $this->assertContains('app/Models/Expense.php', $paths);
        $this->assertContains('app/Http/Controllers/ExpenseController.php', $paths);
        $this->assertContains('app/Policies/ExpensePolicy.php', $paths);
        $this->assertNotContains('app/Models/Booking.php', $paths, 'unrelated code is not pulled in');
        $this->assertNotContains('tests/Feature/ExpenseExportTest.php', $paths, 'tests are listed separately');

        $this->assertSame(['tests/Feature/ExpenseExportTest.php'], array_column($impact['expected_tests'], 'path'));
        $this->assertFalse($impact['new_tests_needed']);

        $this->assertNotSame('none', $impact['areas']['backend']['level']);
        $this->assertNotSame('none', $impact['areas']['database']['level']);
        $this->assertNotSame('none', $impact['areas']['security']['level'], 'admin / read-only access is security-relevant');

        $roles = array_column($impact['agents'], 'role');
        $this->assertContains('BE', $roles);
        $this->assertContains('SE', $roles);
        $this->assertContains('QA', $roles);
        $this->assertContains('SR', $roles);
        $this->assertGreaterThan(0, $impact['token_estimate']);
        $this->assertContains($impact['complexity'], ['S', 'M', 'L', 'XL']);
    }

    public function test_every_expected_file_says_why()
    {
        $impact = $this->analyze('Expense report export');

        foreach ($impact['expected_files'] as $file) {
            $this->assertNotEmpty($file['reasons'], $file['path']);
        }
    }

    public function test_requests_matching_nothing_ask_for_clarification_and_new_tests()
    {
        $impact = $this->analyze('Something about loyalty points');

        $this->assertSame([], $impact['expected_files']);
        $this->assertTrue($impact['new_tests_needed']);
        $this->assertSame('BA', $impact['agents'][0]['role']);
    }

    public function test_modernization_and_legacy_projects_are_flagged()
    {
        $this->project->connection->forceFill(['detected_stack' => ['backend' => ['framework' => 'Laravel', 'version' => '7.30.6']]])->save();

        $impact = $this->analyze('Upgrade Laravel 7 to 10 and move Mix to Vite');

        $this->assertStringContainsString('modernization request', implode(' ', $impact['notes']));
        $this->assertStringContainsString('Legacy Laravel 7', implode(' ', $impact['notes']));
        $this->assertContains($impact['complexity'], ['L', 'XL']);
    }

    public function test_a_small_change_on_a_legacy_project_stays_small()
    {
        // Regression: the legacy note mentions "modernization" and once inflated every legacy task to XL.
        $this->project->connection->forceFill(['detected_stack' => ['backend' => ['framework' => 'Laravel', 'version' => '7.30.6']]])->save();

        $impact = $this->analyze('Rename the booking label');

        $this->assertStringContainsString('Legacy Laravel 7', implode(' ', $impact['notes']));
        $this->assertContains($impact['complexity'], ['S', 'M']);
    }

    public function test_without_an_index_it_says_so()
    {
        $this->project->forceFill(['index_build_id' => null])->save();

        $impact = $this->analyze('Add expenses export');

        $this->assertSame([], $impact['expected_files']);
        $this->assertStringContainsString('No code index yet', implode(' ', $impact['notes']));
    }

    public function test_terms_split_camel_case_and_drop_filler_words()
    {
        $this->assertSame(['expense', 'controller', 'export', 'excel'], app(ImpactAnalyzer::class)->terms('Add ExpenseController export to Excel for the user'));
    }
}
