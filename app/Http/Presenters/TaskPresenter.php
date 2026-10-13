<?php

namespace App\Http\Presenters;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskTransition;
use App\Tasks\TaskStateMachine;

class TaskPresenter
{
    /**
     * Kanban card (Plan §30): only fields that are real today. Agent, model,
     * tokens used and review score appear as their phases land.
     *
     * @return array<string, mixed>
     */
    public static function card(Task $task): array
    {
        $open = $task->relationLoaded('dependencies') ? $task->openDependencies() : collect();

        return [
            'id' => $task->id,
            'number' => $task->number,
            'key' => $task->key(),
            'title' => $task->title,
            'type' => $task->type->value,
            'priority' => $task->priority->value,
            'status' => $task->status->value,
            'blocked_from' => $task->blocked_from?->value,
            'blocked_reason' => $task->blocked_reason,
            'position' => $task->position,
            'version' => $task->version,
            'complexity' => $task->impact['complexity'] ?? null,
            'token_estimate' => $task->impact['token_estimate'] ?? null,
            'waiting_on' => $open->map(fn (Task $d) => ['key' => $d->key(), 'number' => $d->number, 'status' => $d->status->value])->values(),
            'comments_count' => $task->comments_count ?? null,
            'workspace_status' => $task->relationLoaded('workspace') ? $task->workspace?->status->value : null,
            'agent_run' => $task->relationLoaded('latestAgentRun') && $task->latestAgentRun ? [
                'agent' => $task->latestAgentRun->agent->name,
                'role' => $task->latestAgentRun->agent->role->key,
                'status' => $task->latestAgentRun->status->value,
                'model' => $task->latestAgentRun->model?->display_name,
                'tokens' => $task->latestAgentRun->total_tokens,
                'token_budget' => $task->latestAgentRun->token_budget,
            ] : null,
            'user_targets' => array_map(fn (TaskStatus $s) => $s->value, TaskStateMachine::userTargets($task)),
            'updated_at' => $task->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Task $task): array
    {
        return [
            ...self::card($task),
            'description' => $task->description,
            'acceptance_criteria' => $task->acceptance_criteria ?? [],
            'impact' => $task->impact,
            'impact_analyzed_at' => $task->impact_analyzed_at?->toIso8601String(),
            'token_budget' => $task->token_budget,
            'created_by' => $task->creator?->name,
            'created_at' => $task->created_at?->toIso8601String(),
            'started_at' => $task->started_at?->toIso8601String(),
            'completed_at' => $task->completed_at?->toIso8601String(),
            'dependencies' => $task->dependencies->map(fn (Task $d) => ['id' => $d->id, 'number' => $d->number, 'key' => $d->key(), 'title' => $d->title, 'status' => $d->status->value])->values(),
            'dependents' => $task->dependents->map(fn (Task $d) => ['id' => $d->id, 'number' => $d->number, 'key' => $d->key(), 'title' => $d->title, 'status' => $d->status->value])->values(),
            'comments' => $task->comments->map(fn (TaskComment $c) => [
                'id' => $c->id,
                'kind' => $c->kind,
                'body' => $c->body,
                'author' => $c->user?->name,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values(),
            'transitions' => $task->transitions->map(fn (TaskTransition $t) => [
                'id' => $t->id,
                'from' => $t->from_status?->value,
                'to' => $t->to_status->value,
                'actor' => $t->actor,
                'user' => $t->user?->name,
                'reason' => $t->reason,
                'created_at' => $t->created_at?->toIso8601String(),
            ])->values(),
            'workspace' => $task->workspace?->toClientArray(),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function columns(): array
    {
        return array_map(fn (TaskStatus $s) => ['value' => $s->value, 'label' => $s->label(), 'agent_only' => $s->isAgentWork()], TaskStatus::board());
    }
}
