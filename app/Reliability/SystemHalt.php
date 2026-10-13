<?php

namespace App\Reliability;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The emergency-stop flag (Plan §57). Stored in the database (source of truth)
 * and cached for workers; every worker checks it before each step and
 * between tool calls. A global halt stops every organization.
 *
 * Use App\Actions\Operations\HaltOperations / ResumeOperations to change it —
 * they also pause/resume workflows, record events and write the audit log.
 */
class SystemHalt
{
    public const GLOBAL_KEY = 'operations.halt.global';

    public function isHalted(?int $organizationId = null): bool
    {
        if ($this->state(null) !== null) {
            return true;
        }

        return $organizationId !== null && $this->state($organizationId) !== null;
    }

    /**
     * @return array{reason: string, halted_by: int|null, halted_at: string, paused_workflow_ids: list<int>}|null
     */
    public function state(?int $organizationId): ?array
    {
        $key = $this->key($organizationId);

        $value = Cache::rememberForever("eaj:{$key}", function () use ($key) {
            $raw = DB::table('system_settings')->where('key', $key)->value('value');

            // Cache `false` (not null) so "not halted" is cached too.
            return $raw ? json_decode($raw, true) : false;
        });

        return $value ?: null;
    }

    /**
     * @param  list<int>  $pausedWorkflowIds
     */
    public function set(?Organization $organization, User $by, string $reason, array $pausedWorkflowIds): void
    {
        $key = $this->key($organization?->getKey());

        DB::table('system_settings')->updateOrInsert(['key' => $key], [
            'value' => json_encode([
                'reason' => $reason,
                'halted_by' => $by->getKey(),
                'halted_at' => now()->toIso8601String(),
                'paused_workflow_ids' => array_values($pausedWorkflowIds),
            ]),
            'updated_by' => $by->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->forget($key);
    }

    /**
     * @param  list<int>  $workflowIds
     */
    public function addPausedWorkflows(?int $organizationId, array $workflowIds): void
    {
        $key = $this->key($organizationId);
        $raw = DB::table('system_settings')->where('key', $key)->lockForUpdate()->value('value');

        if (! $raw) {
            return;
        }

        $value = json_decode($raw, true);
        $value['paused_workflow_ids'] = array_values(array_unique([...$value['paused_workflow_ids'], ...$workflowIds]));

        DB::table('system_settings')->where('key', $key)->update(['value' => json_encode($value), 'updated_at' => now()]);
        $this->forget($key);
    }

    public function clear(?Organization $organization): void
    {
        $key = $this->key($organization?->getKey());

        DB::table('system_settings')->where('key', $key)->delete();

        $this->forget($key);
    }

    /**
     * Forget now, and again after commit: another process may re-cache the old
     * value between this write and the commit becoming visible.
     */
    private function forget(string $key): void
    {
        Cache::forget("eaj:{$key}");
        DB::afterCommit(fn () => Cache::forget("eaj:{$key}"));
    }

    private function key(?int $organizationId): string
    {
        return $organizationId === null ? self::GLOBAL_KEY : "operations.halt.organization.{$organizationId}";
    }
}
