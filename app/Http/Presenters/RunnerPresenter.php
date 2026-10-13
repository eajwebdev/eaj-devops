<?php

namespace App\Http\Presenters;

use App\Models\LocalRunner;

class RunnerPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function make(LocalRunner $runner): array
    {
        $status = $runner->status();

        return [
            'id' => $runner->id,
            'name' => $runner->name,
            'hostname' => $runner->hostname,
            'os' => $runner->os,
            'platform' => $runner->platform,
            'version' => $runner->version,
            'status' => $status->value,
            'status_label' => $status->label(),
            'allowed_roots' => $runner->allowed_roots ?? [],
            'workspaces_root' => $runner->workspaces_root,
            'last_heartbeat_at' => $runner->last_heartbeat_at?->toIso8601String(),
            'paired_by' => $runner->user?->name,
            'created_at' => $runner->created_at?->toIso8601String(),
        ];
    }
}
