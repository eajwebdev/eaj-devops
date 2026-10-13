<?php

namespace App\Tasks;

use App\Enums\Permission;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskTransition;
use App\Models\User;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Every task mutation goes through here: numbering, the state machine (§31),
 * optimistic concurrency, Kanban order, the dependency DAG (§54), and the
 * persisted event + audit trail for each change (§59, §61).
 */
class TaskService
{
    private const GAP = 1024.0;

    public function __construct(
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly ImpactAnalyzer $impact,
    ) {}

    /**
     * @param  array{title: string, description?: string|null, acceptance_criteria?: list<string>, type: string, priority: string, status?: string, token_budget?: int|null}  $data
     */
    public function create(Project $project, User $user, array $data): Task
    {
        $status = TaskStatus::from($data['status'] ?? TaskStatus::Backlog->value);

        if (! in_array($status, [TaskStatus::Backlog, TaskStatus::Ready], true)) {
            throw new TaskException('New tasks start in Backlog or Ready.');
        }

        $task = DB::transaction(function () use ($project, $user, $data, $status) {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $number = $locked->task_sequence + 1;
            $locked->forceFill(['task_sequence' => $number])->save();

            $task = Task::forceCreate([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'number' => $number,
                'title' => trim($data['title']),
                'description' => $this->clean($data['description'] ?? null),
                'acceptance_criteria' => $this->criteria($data['acceptance_criteria'] ?? []),
                'type' => TaskType::from($data['type']),
                'priority' => TaskPriority::from($data['priority']),
                'status' => $status,
                'token_budget' => $data['token_budget'] ?? null,
                'position' => $this->endOf($project->id, $status),
                'created_by' => $user->id,
                'version' => 0,
            ]);
            $task->setRelation('project', $project);

            $this->recordTransition($task, null, $status, 'user', $user, 'Created');
            $this->events->record('task.created', ['task' => $this->summary($task)], $project, actor: $user);
            $this->audit->log('task.created', $user, project: $project, subject: $task, metadata: ['key' => $task->key()]);

            return $task;
        });

        $this->analyze($task);

        return $task->refresh();
    }

    /**
     * @param  array{title?: string, description?: string|null, acceptance_criteria?: list<string>, type?: string, priority?: string, token_budget?: int|null}  $data
     */
    public function update(Task $task, User $user, array $data, int $expectedVersion): Task
    {
        $changed = DB::transaction(function () use ($task, $user, $data, $expectedVersion) {
            $locked = $this->lock($task);

            if ($locked->version !== $expectedVersion) {
                throw TaskException::conflict('Someone else changed this task meanwhile. Reload to see their changes.');
            }

            if (isset($data['title'])) {
                $locked->title = trim($data['title']);
            }
            if (array_key_exists('description', $data)) {
                $locked->description = $this->clean($data['description']);
            }
            if (array_key_exists('acceptance_criteria', $data)) {
                $locked->acceptance_criteria = $this->criteria($data['acceptance_criteria'] ?? []);
            }
            if (isset($data['type'])) {
                $locked->type = TaskType::from($data['type']);
            }
            if (isset($data['priority'])) {
                $locked->priority = TaskPriority::from($data['priority']);
            }
            if (array_key_exists('token_budget', $data)) {
                $locked->token_budget = $data['token_budget'];
            }

            $changed = array_keys($locked->getDirty());

            if ($changed === []) {
                return [];
            }

            $locked->forceFill(['version' => $locked->version + 1])->save();
            $task->setRawAttributes($locked->getAttributes(), true);

            $this->events->record('task.updated', ['task' => $this->summary($locked), 'fields' => $changed], $locked->project, actor: $user);
            $this->audit->log('task.updated', $user, project: $locked->project, subject: $locked, metadata: ['fields' => $changed]);

            return $changed;
        });

        if (array_intersect($changed, ['title', 'description', 'acceptance_criteria']) !== []) {
            $this->analyze($task);
        }

        return $task->refresh();
    }

    /**
     * @param  'user'|'system'  $actor
     * @param  TaskStatus|null  $expectedFrom  what the caller believes the status is (stale boards get a conflict)
     */
    public function transition(Task $task, TaskStatus $to, string $actor, ?User $user, ?string $reason = null, ?TaskStatus $expectedFrom = null): Task
    {
        return DB::transaction(function () use ($task, $to, $actor, $user, $reason, $expectedFrom) {
            $locked = $this->lock($task);
            $from = $locked->status;

            if ($expectedFrom && $from !== $expectedFrom) {
                throw TaskException::conflict("{$locked->key()} is now in {$from->label()}. Reload to see the latest board.");
            }

            if (! TaskStateMachine::exists($from, $to)) {
                throw new TaskException("{$locked->key()} cannot move from {$from->label()} to {$to->label()}.");
            }

            if ($actor === 'user') {
                if (! TaskStateMachine::userMay($from, $to, $locked->blocked_from)) {
                    throw new TaskException($to->isAgentWork()
                        ? "{$to->label()} is set by the AI team while it works on the task — it can't be set by hand."
                        : "{$locked->key()} cannot be moved from {$from->label()} to {$to->label()} by hand.");
                }

                if ($to === TaskStatus::Done && ! $user?->hasPermissionIn($locked->project->organization, Permission::TasksApprove)) {
                    throw new TaskException('Only an owner or project manager can approve a release-ready task.');
                }
            }

            if ($to === TaskStatus::Blocked && trim((string) $reason) === '') {
                throw new TaskException('Say why the task is blocked.');
            }

            if ($to === TaskStatus::Planning) {
                $open = $locked->load('dependencies')->openDependencies();

                if ($open->isNotEmpty()) {
                    throw new TaskException('Waiting on '.$open->map->key()->join(', ').' — dependencies must be completed first.');
                }
            }

            $locked->forceFill([
                'status' => $to,
                'blocked_from' => $to === TaskStatus::Blocked ? $from : null,
                'blocked_reason' => $to === TaskStatus::Blocked ? Str::limit(trim((string) $reason), 500, '') : null,
                'position' => $this->endOf($locked->project_id, $to),
                'started_at' => $to === TaskStatus::Planning ? ($locked->started_at ?? now()) : $locked->started_at,
                'completed_at' => $to === TaskStatus::Done ? now() : ($to === TaskStatus::Backlog ? null : $locked->completed_at),
                'version' => $locked->version + 1,
            ])->save();

            $this->recordTransition($locked, $from, $to, $actor, $user, $reason);
            $this->events->record('task.status.changed', [
                'task' => $this->summary($locked),
                'from' => $from->value,
                'to' => $to->value,
                'actor' => $actor,
                'reason' => $reason ? Str::limit($reason, 300) : null,
            ], $locked->project, actor: $user);

            if ($actor === 'user') {
                $this->audit->log('task.status.changed', $user, project: $locked->project, subject: $locked, metadata: ['from' => $from->value, 'to' => $to->value, 'reason' => $reason]);
            }

            $task->setRawAttributes($locked->getAttributes(), true);

            return $task;
        });
    }

    /**
     * Kanban drop (Plan §30): optional status change, then order between neighbours.
     */
    public function move(Task $task, User $user, TaskStatus $to, TaskStatus $expectedFrom, ?int $afterId, ?int $beforeId, ?string $reason = null): Task
    {
        return DB::transaction(function () use ($task, $user, $to, $expectedFrom, $afterId, $beforeId, $reason) {
            if ($expectedFrom !== $to) {
                $this->transition($task, $to, 'user', $user, $reason, $expectedFrom);
            } elseif ($this->lock($task)->status !== $to) {
                throw TaskException::conflict("{$task->key()} moved meanwhile. Reload to see the latest board.");
            }

            $neighbours = Task::query()
                ->where('project_id', $task->project_id)
                ->where('status', $to)
                ->whereKey(array_filter([$afterId, $beforeId]))
                ->pluck('position', 'id');

            $after = $afterId ? $neighbours[$afterId] ?? null : null;
            $before = $beforeId ? $neighbours[$beforeId] ?? null : null;

            $position = match (true) {
                $after !== null && $before !== null => ($after + $before) / 2,
                $after !== null => $after + self::GAP,
                $before !== null => $before - self::GAP,
                default => $task->position,
            };

            if ($position !== $task->position) {
                $locked = $this->lock($task);
                $locked->forceFill(['position' => $position])->save();
                $task->setRawAttributes($locked->getAttributes(), true);

                $this->events->record('task.reordered', ['task' => $this->summary($locked)], $locked->project, actor: $user);
            }

            return $task;
        });
    }

    public function comment(Task $task, User $user, string $body, string $kind = 'comment'): TaskComment
    {
        return DB::transaction(function () use ($task, $user, $body, $kind) {
            $comment = TaskComment::forceCreate([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'kind' => $kind,
                'body' => trim($body),
            ]);

            $this->events->record('task.comment.added', [
                'task' => $this->summary($task),
                'kind' => $kind,
                'excerpt' => Str::limit(trim($body), 140),
            ], $task->project, actor: $user);

            return $comment;
        });
    }

    /**
     * Request changes (Plan §95): the reason is kept as a comment the specialist will read.
     */
    public function requestChanges(Task $task, User $user, string $feedback): Task
    {
        return DB::transaction(function () use ($task, $user, $feedback) {
            $this->comment($task, $user, $feedback, 'changes_requested');

            return $this->transition($task, TaskStatus::ChangesRequested, 'user', $user, Str::limit($feedback, 300));
        });
    }

    public function addDependency(Task $task, Task $dependsOn, User $user): void
    {
        if ($task->project_id !== $dependsOn->project_id) {
            throw new TaskException('Dependencies must be in the same project.');
        }

        if ($task->is($dependsOn)) {
            throw new TaskException('A task cannot depend on itself.');
        }

        DB::transaction(function () use ($task, $dependsOn, $user) {
            // Lock both rows in a stable order so concurrent edits cannot create a cycle.
            Task::whereKey([$task->id, $dependsOn->id])->orderBy('id')->lockForUpdate()->get();

            if ($this->reaches($dependsOn->id, $task->id)) {
                throw new TaskException("{$dependsOn->key()} already depends on {$task->key()} — that would create a cycle.");
            }

            if ($task->dependencies()->whereKey($dependsOn->id)->exists()) {
                return;
            }

            $task->dependencies()->attach($dependsOn->id, ['created_by' => $user->id, 'created_at' => now()]);

            $this->events->record('task.dependency.added', ['task' => $this->summary($task), 'depends_on' => $this->summary($dependsOn)], $task->project, actor: $user);
        });
    }

    public function removeDependency(Task $task, Task $dependsOn, User $user): void
    {
        DB::transaction(function () use ($task, $dependsOn, $user) {
            if ($task->dependencies()->detach($dependsOn->id) > 0) {
                $this->events->record('task.dependency.removed', ['task' => $this->summary($task), 'depends_on' => $this->summary($dependsOn)], $task->project, actor: $user);
            }
        });
    }

    /**
     * (Re)run the deterministic change-impact analysis (Plan §67). Never fails the caller.
     */
    public function analyze(Task $task): void
    {
        try {
            $impact = $this->impact->analyze($task->loadMissing('project.connection'));
        } catch (Throwable $e) {
            report($e);

            return;
        }

        DB::transaction(function () use ($task, $impact) {
            $task->forceFill([
                'impact' => $impact,
                'impact_analyzed_at' => now(),
                'token_budget' => $task->token_budget ?? ($impact['token_estimate'] ?? null),
            ])->save();

            $this->events->record('task.impact.analyzed', [
                'task' => $this->summary($task),
                'complexity' => $impact['complexity'] ?? null,
                'files' => count($impact['expected_files'] ?? []),
                'areas' => array_keys(array_filter($impact['areas'] ?? [], fn ($a) => ($a['level'] ?? 'none') !== 'none')),
            ], $task->project);
        });
    }

    /**
     * @return array{id: int, key: string, title: string, status: string, priority: string}
     */
    public function summary(Task $task): array
    {
        return [
            'id' => $task->id,
            'key' => $task->key(),
            'number' => $task->number,
            'title' => $task->title,
            'status' => $task->status->value,
            'priority' => $task->priority->value,
        ];
    }

    /**
     * Is `$to` reachable from `$from` by following "depends on" edges?
     */
    private function reaches(int $from, int $to): bool
    {
        $seen = [];
        $frontier = [$from];

        while ($frontier) {
            if (in_array($to, $frontier, true)) {
                return true;
            }

            $seen = array_merge($seen, $frontier);
            $frontier = DB::table('task_dependencies')
                ->whereIn('task_id', $frontier)
                ->pluck('depends_on_task_id')
                ->map(fn ($id) => (int) $id)
                ->diff($seen)
                ->values()
                ->all();
        }

        return false;
    }

    private function lock(Task $task): Task
    {
        $locked = Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('project', $task->relationLoaded('project') ? $task->project : $locked->project);

        return $locked;
    }

    private function endOf(int $projectId, TaskStatus $status): float
    {
        return (float) (Task::where('project_id', $projectId)->where('status', $status)->max('position') ?? 0) + self::GAP;
    }

    private function recordTransition(Task $task, ?TaskStatus $from, TaskStatus $to, string $actor, ?User $user, ?string $reason): void
    {
        TaskTransition::forceCreate([
            'task_id' => $task->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor' => $actor,
            'user_id' => $user?->id,
            'reason' => $reason ? Str::limit($reason, 500, '') : null,
        ]);
    }

    private function clean(?string $text): ?string
    {
        $text = $text === null ? null : trim($text);

        return $text === '' ? null : $text;
    }

    /**
     * @param  list<string>  $items
     * @return list<string>|null
     */
    private function criteria(array $items): ?array
    {
        $clean = array_values(array_filter(array_map(fn ($i) => Str::limit(trim((string) $i), 500, ''), $items), fn ($i) => $i !== ''));

        return $clean === [] ? null : array_slice($clean, 0, 30);
    }
}
