<?php

namespace App\Http\Presenters;

use App\Models\Project;

class ProjectPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function make(Project $project): array
    {
        $connection = $project->connection;

        return [
            'id' => $project->id,
            'key' => $project->key,
            'name' => $project->name,
            'slug' => $project->slug,
            'description' => $project->description,
            'status' => $project->status,
            'created_at' => $project->created_at?->toIso8601String(),
            'connection' => $connection ? [
                'type' => $connection->type->value,
                'type_label' => $connection->type->label(),
                'status' => $connection->status->value,
                'status_label' => $connection->status->label(),
                'local_path' => $connection->local_path,
                'repository_url' => $connection->repository_url,
                'git_enabled' => $connection->git_enabled,
                'detected_stack' => $connection->detected_stack,
                'git_state' => $connection->git_state,
                'warnings' => $connection->warnings ?? [],
                'last_scanned_at' => $connection->last_scanned_at?->toIso8601String(),
                'indexed_at' => $connection->indexed_at?->toIso8601String(),
                'index_stats' => $connection->index_stats,
                'last_error' => $connection->last_error,
                'local_runner_id' => $connection->local_runner_id,
            ] : null,
        ];
    }
}
