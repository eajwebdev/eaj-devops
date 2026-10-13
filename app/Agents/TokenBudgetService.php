<?php

namespace App\Agents;

use App\Models\AgentRun;
use App\Models\TokenBudget;
use App\Models\TokenUsage;
use Illuminate\Database\Eloquent\Builder;

/** Hard budget checks happen outside model output (Plan Sections 47 and 48.1). */
class TokenBudgetService
{
    /** @return array{allowed: bool, remaining: int, limit: int, used: int, scope: string} */
    public function headroom(AgentRun $run, int $pendingTokens = 0): array
    {
        $constraints = [[
            'scope' => 'run',
            'limit' => (int) $run->token_budget,
            'used' => (int) $run->total_tokens,
        ]];

        if (($taskLimit = $run->task()->value('token_budget')) !== null) {
            $constraints[] = [
                'scope' => 'task',
                'limit' => (int) $taskLimit,
                'used' => (int) TokenUsage::where('task_id', $run->task_id)->sum('total_tokens'),
            ];
        }

        $budgets = TokenBudget::where('organization_id', $run->organization_id)
            ->where('enabled', true)
            ->where(function ($query) use ($run) {
                $query->where(fn ($q) => $q->where('scope_type', 'organization')->where('scope_id', $run->organization_id))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'project')->where('scope_id', $run->project_id))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'task')->where('scope_id', $run->task_id))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'agent')->where('scope_id', $run->agent_id));
            })->get();

        foreach ($budgets as $budget) {
            $query = TokenUsage::query();
            $this->scopeUsage($query, $budget->scope_type, (int) $budget->scope_id, $run->id);

            if ($budget->period === 'day') {
                $query->where('recorded_at', '>=', now()->startOfDay());
            } elseif ($budget->period === 'month') {
                $query->where('recorded_at', '>=', now()->startOfMonth());
            } elseif ($budget->period === 'run') {
                $query->where('agent_run_id', $run->id);
            }

            $constraints[] = [
                'scope' => "{$budget->scope_type}:{$budget->period}",
                'limit' => (int) $budget->max_tokens,
                'used' => (int) $query->sum('total_tokens'),
            ];
        }

        $tightest = collect($constraints)->sortBy(fn ($item) => $item['limit'] - $item['used'])->first();
        $remaining = max(0, $tightest['limit'] - $tightest['used']);

        return [...$tightest, 'remaining' => $remaining, 'allowed' => $pendingTokens <= $remaining];
    }

    private function scopeUsage(Builder $query, string $type, int $id, int $runId): void
    {
        match ($type) {
            'organization' => $query->where('organization_id', $id),
            'project' => $query->where('project_id', $id),
            'task' => $query->where('task_id', $id),
            'agent' => $query->where('agent_id', $id),
            default => $query->where('agent_run_id', $runId)->whereRaw('1 = 0'),
        };
    }
}
