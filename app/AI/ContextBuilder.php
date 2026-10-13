<?php

namespace App\AI;

use App\Models\ProjectIndexSymbol;
use App\Models\SkillVersion;
use App\Models\Task;
use App\Security\SecretRedactor;
use Illuminate\Support\Collection;

class ContextBuilder
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    /** @param Collection<int, SkillVersion> $skills @return array<string, mixed> */
    public function build(Task $task, Collection $skills): array
    {
        $task->loadMissing(['project.connection', 'dependencies.project', 'comments']);
        $impact = $task->impact ?? [];
        $paths = collect([...(array) ($impact['expected_files'] ?? []), ...(array) ($impact['expected_tests'] ?? [])])
            ->pluck('path')->filter()->unique()->take(30)->values();
        $symbols = collect();
        if ($task->project->index_build_id && $paths->isNotEmpty()) {
            $symbols = ProjectIndexSymbol::query()->with('file:id,path')->where('project_id', $task->project_id)
                ->where('build_id', $task->project->index_build_id)->whereHas('file', fn ($query) => $query->whereIn('path', $paths))
                ->limit(200)->get()->groupBy(fn ($symbol) => $symbol->file->path)
                ->map(fn ($items) => $items->map->only(['kind', 'name', 'line', 'detail'])->values()->all());
        }

        return $this->redactor->redact([
            'task' => ['id' => $task->key(), 'title' => $task->title, 'description' => $task->description, 'acceptance_criteria' => $task->acceptance_criteria ?? [], 'type' => $task->type->value, 'priority' => $task->priority->value],
            'stack' => $task->project->connection?->detected_stack ?? [],
            'impact' => ['areas' => $impact['areas'] ?? [], 'expected_files' => $impact['expected_files'] ?? [], 'expected_tests' => $impact['expected_tests'] ?? [], 'notes' => $impact['notes'] ?? []],
            'indexed_symbols' => $symbols->all(),
            'dependencies' => $task->dependencies->map(fn (Task $dependency) => ['task' => $dependency->key(), 'title' => $dependency->title, 'status' => $dependency->status->value])->values()->all(),
            'instructions' => $task->comments->whereIn('kind', ['instruction', 'changes_requested'])->take(-10)->map->only(['kind', 'body'])->values()->all(),
            'skills' => $this->skills($skills),
            'capabilities' => ['source_content_available' => false, 'repository_writes_available' => false, 'test_execution_available' => false, 'note' => 'Planning works from index metadata only. Implementation and review runs receive file contents and tool results.'],
        ]);
    }

    /**
     * The skill versions a run recorded (§37–38).
     *
     * @param  Collection<int, SkillVersion>  $skills
     * @return list<array<string, mixed>>
     */
    public function skills(Collection $skills): array
    {
        return $skills->map(fn (SkillVersion $skill) => ['id' => $skill->id, 'version' => $skill->version, 'responsibilities' => $skill->responsibilities, 'rules' => $skill->rules, 'forbidden_actions' => $skill->forbidden_actions, 'validation_expectations' => $skill->validation_expectations])->values()->all();
    }
}
