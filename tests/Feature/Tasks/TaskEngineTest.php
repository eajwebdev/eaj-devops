<?php

namespace Tests\Feature\Tasks;

use App\Enums\OrganizationRole;
use App\Enums\TaskStatus;
use App\Enums\WorkspaceStatus;
use App\Jobs\PublishOutboxEvents;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RunnerCommand;
use App\Models\Task;
use App\Models\User;
use App\Runner\RunnerPairing;
use App\Tasks\TaskException;
use App\Tasks\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Plan Phase 3 — tasks, priorities, dependencies, state machine, Kanban, comments.
 */
class TaskEngineTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake([PublishOutboxEvents::class]);
        $this->organization = Organization::factory()->create();
        $this->owner = User::factory()->memberOf($this->organization)->create();
        $this->project = Project::factory()->for($this->organization)->create(['key' => 'BOOK', 'slug' => 'booking']);
    }

    private function newTask(array $overrides = []): Task
    {
        return app(TaskService::class)->create($this->project, $this->owner, [
            'title' => 'Monthly revenue dashboard',
            'type' => 'feature',
            'priority' => 'HIGH',
            ...$overrides,
        ]);
    }

    private function move(Task $task, TaskStatus $to, array $extra = [])
    {
        return $this->actingAs($this->owner)->post("/projects/booking/tasks/{$task->number}/transition", ['to' => $to->value, 'from' => $task->status->value, ...$extra]);
    }

    public function test_creating_a_task_numbers_it_per_project_and_records_everything()
    {
        $this->actingAs($this->owner)->post('/projects/booking/tasks', [
            'title' => 'Add expenses export to Excel',
            'description' => "Only Admin and Super Admin can access it.\nBranch Managers must remain read-only.",
            'acceptance_criteria' => ['Exports the filtered month', '', 'Read-only for branch managers'],
            'type' => 'feature',
            'priority' => 'HIGH',
        ])->assertRedirect('/projects/booking/tasks/1');

        $second = $this->newTask(['title' => 'Second']);
        $this->assertSame(2, $second->number);
        $this->assertSame('BOOK-2', $second->key());

        $task = Task::where('number', 1)->sole();
        $this->assertSame(TaskStatus::Backlog, $task->status);
        $this->assertSame(['Exports the filtered month', 'Read-only for branch managers'], $task->acceptance_criteria);
        $this->assertNotNull($task->impact, 'impact analysis runs on create');
        $this->assertSame(1, $task->transitions()->count());
        $this->assertDatabaseHas('outbox_events', ['type' => 'task.created', 'project_id' => $this->project->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.created', 'user_id' => $this->owner->id]);
    }

    public function test_numbers_stay_per_project()
    {
        $other = Project::factory()->for($this->organization)->create();

        $this->newTask();
        $task = app(TaskService::class)->create($other, $this->owner, ['title' => 'x', 'type' => 'bug', 'priority' => 'LOW']);

        $this->assertSame(1, $task->number);
    }

    public function test_viewers_can_see_but_not_change_tasks()
    {
        $task = $this->newTask();
        $viewer = User::factory()->memberOf($this->organization, OrganizationRole::Viewer)->create();

        $this->actingAs($viewer)->get('/projects/booking/tasks/1')->assertOk();
        $this->actingAs($viewer)->post('/projects/booking/tasks', ['title' => 'x', 'type' => 'bug', 'priority' => 'LOW'])->assertForbidden();
        $this->actingAs($viewer)->post('/projects/booking/tasks/1/transition', ['to' => 'READY', 'from' => 'BACKLOG'])->assertForbidden();
        $this->actingAs($viewer)->post('/projects/booking/tasks/1/comments', ['body' => 'hi'])->assertForbidden();
        $this->assertSame(TaskStatus::Backlog, $task->fresh()->status);
    }

    public function test_tasks_of_other_organizations_are_not_found()
    {
        $foreign = Task::factory()->create();

        $this->actingAs($this->owner)->get("/projects/{$foreign->project->slug}/tasks/{$foreign->number}")->assertNotFound();
        $this->actingAs($this->owner)->get('/projects/booking/tasks/'.$foreign->number)->assertNotFound();
        $this->actingAs($this->owner)->get('/tasks?project='.$foreign->project->slug)->assertInertia(fn (Assert $page) => $page->where('project.id', $this->project->id));
    }

    public function test_people_can_triage_but_not_fake_agent_progress()
    {
        $task = $this->newTask();

        $this->move($task, TaskStatus::Ready)->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);

        $this->move($task->fresh(), TaskStatus::Planning)->assertSessionHasErrors(['task' => 'Planning is set by the AI team while it works on the task — it can\'t be set by hand.']);
        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
    }

    public function test_a_stale_board_gets_a_conflict_instead_of_overwriting()
    {
        $task = $this->newTask();
        app(TaskService::class)->transition($task, TaskStatus::Ready, 'user', $this->owner);

        $this->actingAs($this->owner)
            ->post('/projects/booking/tasks/1/transition', ['to' => 'CANCELLED', 'from' => 'BACKLOG'])
            ->assertSessionHasErrors('task')
            ->assertSessionHas('conflict', true);

        $this->assertSame(TaskStatus::Ready, $task->fresh()->status);
    }

    public function test_blocking_requires_a_reason_and_unblocking_returns_to_the_previous_state()
    {
        $task = $this->newTask();
        app(TaskService::class)->transition($task, TaskStatus::Ready, 'user', $this->owner);

        $this->move($task->fresh(), TaskStatus::Blocked)->assertSessionHasErrors('reason');
        $this->move($task->fresh(), TaskStatus::Blocked, ['reason' => 'Waiting for client sign-off'])->assertSessionHasNoErrors();

        $task->refresh();
        $this->assertSame(TaskStatus::Blocked, $task->status);
        $this->assertSame(TaskStatus::Ready, $task->blocked_from);
        $this->assertSame('Waiting for client sign-off', $task->blocked_reason);

        $this->move($task, TaskStatus::Backlog)->assertSessionHasErrors('task');
        $this->move($task, TaskStatus::Ready)->assertSessionHasNoErrors();
        $this->assertNull($task->fresh()->blocked_reason);
    }

    public function test_dependencies_form_a_dag_and_gate_the_start_of_work()
    {
        $service = app(TaskService::class);
        $api = $this->newTask(['title' => 'API contract']);
        $ui = $this->newTask(['title' => 'Dashboard UI']);
        $qa = $this->newTask(['title' => 'Integration QA']);

        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$ui->number}/dependencies", ['number' => $api->number])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$qa->number}/dependencies", ['number' => $ui->number])->assertSessionHasNoErrors();

        // QA → UI → API: making API depend on QA would close a cycle.
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$api->number}/dependencies", ['number' => $qa->number])
            ->assertSessionHasErrors(['dependency' => 'BOOK-3 already depends on BOOK-1 — that would create a cycle.']);
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$api->number}/dependencies", ['number' => $api->number])->assertSessionHasErrors('dependency');

        // The workflow cannot start UI while API is open.
        $service->transition($ui, TaskStatus::Ready, 'user', $this->owner);
        try {
            $service->transition($ui->fresh(), TaskStatus::Planning, 'system', null);
            $this->fail('should wait for dependencies');
        } catch (TaskException $e) {
            $this->assertSame('Waiting on BOOK-1 — dependencies must be completed first.', $e->getMessage());
        }

        $this->actingAs($this->owner)->get('/tasks?project=booking')->assertInertia(fn (Assert $page) => $page
            ->component('tasks/board')
            ->where('tasks.1.key', 'BOOK-2')
            ->where('tasks.1.waiting_on.0.key', 'BOOK-1'));

        $this->actingAs($this->owner)->delete("/projects/booking/tasks/{$ui->number}/dependencies/{$api->number}")->assertRedirect();
        $service->transition($ui->fresh(), TaskStatus::Planning, 'system', null);
        $this->assertSame(TaskStatus::Planning, $ui->fresh()->status);
        $this->assertNotNull($ui->fresh()->started_at);
    }

    public function test_requesting_changes_needs_feedback_and_keeps_it_as_a_comment()
    {
        $service = app(TaskService::class);
        $task = $this->newTask();
        foreach ([TaskStatus::Ready] as $s) {
            $service->transition($task, $s, 'user', $this->owner);
        }
        foreach ([TaskStatus::Planning, TaskStatus::InProgress, TaskStatus::Testing, TaskStatus::Review] as $s) {
            $service->transition($task, $s, 'system', null);
        }

        $this->move($task->fresh(), TaskStatus::ChangesRequested)->assertSessionHasErrors('reason');
        $this->move($task->fresh(), TaskStatus::ChangesRequested, ['reason' => 'Totals ignore refunds.'])->assertSessionHasNoErrors();

        $this->assertSame(TaskStatus::ChangesRequested, $task->fresh()->status);
        $this->assertDatabaseHas('task_comments', ['task_id' => $task->id, 'kind' => 'changes_requested', 'body' => 'Totals ignore refunds.']);
    }

    public function test_only_approvers_can_complete_a_release_ready_task()
    {
        $service = app(TaskService::class);
        $task = $this->newTask();
        $service->transition($task, TaskStatus::Ready, 'user', $this->owner);
        foreach ([TaskStatus::Planning, TaskStatus::InProgress, TaskStatus::Testing, TaskStatus::Review, TaskStatus::ReleaseReady] as $s) {
            $service->transition($task, $s, 'system', null);
        }
        $developer = User::factory()->memberOf($this->organization, OrganizationRole::Developer)->create();

        $this->actingAs($developer)->post('/projects/booking/tasks/1/transition', ['to' => 'DONE', 'from' => 'RELEASE_READY'])
            ->assertSessionHasErrors(['task' => 'Only an owner or project manager can approve a release-ready task.']);

        $this->move($task->fresh(), TaskStatus::Done)->assertSessionHasNoErrors();
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame(8, $task->transitions()->count());
    }

    public function test_kanban_moves_reorder_between_neighbours()
    {
        $a = $this->newTask(['title' => 'A']);
        $b = $this->newTask(['title' => 'B']);
        $c = $this->newTask(['title' => 'C']);

        // Drop C between A and B in Backlog.
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$c->number}/move", [
            'to' => 'BACKLOG', 'from' => 'BACKLOG', 'after_id' => $a->id, 'before_id' => $b->id,
        ])->assertSessionHasNoErrors();

        $order = Task::where('project_id', $this->project->id)->orderBy('position')->pluck('title')->all();
        $this->assertSame(['A', 'C', 'B'], $order);

        // Drop B into Ready (status change + order).
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$b->number}/move", ['to' => 'READY', 'from' => 'BACKLOG'])->assertSessionHasNoErrors();
        $this->assertSame(TaskStatus::Ready, $b->fresh()->status);

        // Dropping into an agent-only column is refused.
        $this->actingAs($this->owner)->post("/projects/booking/tasks/{$a->number}/move", ['to' => 'TESTING', 'from' => 'BACKLOG'])->assertSessionHasErrors('task');
    }

    public function test_edits_use_optimistic_versions()
    {
        $task = $this->newTask();

        $this->actingAs($this->owner)->patch('/projects/booking/tasks/1', ['version' => 0, 'priority' => 'CRITICAL'])->assertSessionHasNoErrors();
        $this->actingAs($this->owner)->patch('/projects/booking/tasks/1', ['version' => 0, 'title' => 'Stale edit'])
            ->assertSessionHasErrors(['task' => 'Someone else changed this task meanwhile. Reload to see their changes.']);

        $task->refresh();
        $this->assertSame('CRITICAL', $task->priority->value);
        $this->assertSame('Monthly revenue dashboard', $task->title);
        $this->assertSame(1, $task->version);
    }

    public function test_comments_are_recorded_and_shown_on_the_task_page()
    {
        $this->newTask();

        $this->actingAs($this->owner)->post('/projects/booking/tasks/1/comments', ['body' => 'Use the existing export button style.', 'kind' => 'instruction'])->assertRedirect();

        $this->actingAs($this->owner)->get('/projects/booking/tasks/1')->assertInertia(fn (Assert $page) => $page
            ->component('tasks/show')
            ->where('task.key', 'BOOK-1')
            ->where('task.comments.0.kind', 'instruction')
            ->where('task.comments.0.author', $this->owner->name)
            ->where('task.user_targets', ['READY', 'BLOCKED', 'CANCELLED']));
    }

    public function test_preparing_a_workspace_queues_a_worktree_on_the_runner()
    {
        $pairing = app(RunnerPairing::class);
        $runner = $pairing->redeem($pairing->createCode($this->organization, $this->owner)['code'], ['name' => 'PC'], null)['runner'];
        $this->project->connection->forceFill(['local_runner_id' => $runner->id, 'git_enabled' => true])->save();
        $task = $this->newTask();

        $this->actingAs($this->owner)->post('/projects/booking/tasks/1/workspace')->assertSessionHasErrors('workspace');

        app(TaskService::class)->transition($task, TaskStatus::Ready, 'user', $this->owner);
        $this->actingAs($this->owner)->post('/projects/booking/tasks/1/workspace')->assertSessionHasNoErrors();

        $workspace = $task->fresh()->workspace;
        $this->assertSame('BOOK-1', $workspace->task_key);
        $this->assertSame('ai/BOOK-1-monthly-revenue-dashboard', $workspace->branch);
        $this->assertSame(WorkspaceStatus::Creating, $workspace->status);
        $this->assertSame('workspace.create', RunnerCommand::sole()->type->value);
    }

    public function test_command_center_counts_tasks()
    {
        $service = app(TaskService::class);
        $this->newTask();
        $blocked = $this->newTask();
        $service->transition($blocked, TaskStatus::Blocked, 'user', $this->owner, 'Waiting on client');

        $this->actingAs($this->owner)->get('/command-center')->assertInertia(fn (Assert $page) => $page
            ->where('metrics.open_tasks', 2)
            ->where('metrics.needs_decision', 1)
            ->where('metrics.completed_today', 0));
    }
}
