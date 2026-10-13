<?php

namespace Database\Factories;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'organization_id' => fn (array $a) => Project::find($a['project_id'])->organization_id,
            'number' => fn (array $a) => tap(Project::find($a['project_id']), fn ($p) => $p->forceFill(['task_sequence' => $p->task_sequence + 1])->save())->task_sequence,
            'title' => ucfirst(fake()->words(4, true)),
            'type' => TaskType::Feature,
            'priority' => TaskPriority::Medium,
            'status' => TaskStatus::Backlog,
            'position' => fake()->randomFloat(2, 1, 10000),
            'version' => 0,
        ];
    }

    public function status(TaskStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
