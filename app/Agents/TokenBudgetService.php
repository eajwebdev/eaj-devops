<?php

namespace App\Agents;

use App\Models\AgentRun;
use App\Models\BudgetAlert;
use App\Models\TokenBudget;
use App\Models\TokenUsage;
use Illuminate\Database\Eloquent\Builder;

/** Hard caps and 70/90/100 alerts are computed from the immutable usage ledger. */
class TokenBudgetService
{
    /** @return array{allowed: bool, remaining: int, limit: int, used: int, scope: string, metric: string} */
    public function headroom(AgentRun $run, int $pendingTokens = 0): array
    {
        $constraint = collect($this->constraints($run))
            ->where('metric', 'tokens')
            ->where('hard_stop', true)
            ->sortBy('remaining')
            ->first();

        return [
            'scope' => $constraint['scope'],
            'metric' => 'tokens',
            'limit' => (int) $constraint['limit'],
            'used' => (int) $constraint['used'],
            'remaining' => (int) $constraint['remaining'],
            'allowed' => $pendingTokens <= $constraint['remaining'],
        ];
    }

    /** @return array<string, mixed>|null */
    public function blockingConstraint(AgentRun $run, int $pendingTokens, ?float $pendingCost = null): ?array
    {
        return collect($this->constraints($run))
            ->where('hard_stop', true)
            ->filter(function (array $constraint) use ($pendingTokens, $pendingCost) {
                $pending = $constraint['metric'] === 'tokens' ? $pendingTokens : $pendingCost;

                return $pending !== null && $pending > 0 && $pending >= $constraint['remaining'];
            })
            ->sortBy(fn (array $constraint) => $constraint['remaining'] / max(0.000001, (float) $constraint['limit']))
            ->first();
    }

    /** @return list<BudgetAlert> */
    public function recordAlerts(AgentRun $run): array
    {
        $alerts = [];

        foreach ($this->constraints($run) as $constraint) {
            if ((float) $constraint['limit'] <= 0) {
                continue;
            }

            $percent = (float) $constraint['used'] / (float) $constraint['limit'] * 100;
            foreach ([70, 90, 100] as $threshold) {
                if ($percent < $threshold) {
                    continue;
                }

                $alert = BudgetAlert::firstOrCreate([
                    'organization_id' => $run->organization_id,
                    'budget_key' => $constraint['key'],
                    'metric' => $constraint['metric'],
                    'threshold' => $threshold,
                    'period_key' => $constraint['period_key'],
                ], [
                    'project_id' => $run->project_id,
                    'task_id' => $run->task_id,
                    'agent_run_id' => $run->id,
                    'token_budget_id' => $constraint['budget_id'],
                    'scope' => $constraint['scope'],
                    'used' => $constraint['used'],
                    'limit_value' => $constraint['limit'],
                    'hard_stop' => $constraint['hard_stop'],
                    'emitted_at' => now(),
                ]);

                if ($alert->wasRecentlyCreated) {
                    $alerts[] = $alert;
                }
            }
        }

        return $alerts;
    }

    /** @return list<array{key: string, budget_id: int|null, scope: string, metric: string, limit: float|int, used: float|int, remaining: float|int, hard_stop: bool, period_key: string}> */
    public function constraints(AgentRun $run): array
    {
        $constraints = [[
            'key' => "run:{$run->id}",
            'budget_id' => null,
            'scope' => 'run',
            'metric' => 'tokens',
            'limit' => (int) $run->token_budget,
            'used' => (int) $run->total_tokens,
            'hard_stop' => true,
            'period_key' => "run-{$run->id}",
        ]];

        if (($taskLimit = $run->task()->value('token_budget')) !== null) {
            $constraints[] = [
                'key' => "task:{$run->task_id}",
                'budget_id' => null,
                'scope' => 'task',
                'metric' => 'tokens',
                'limit' => (int) $taskLimit,
                'used' => (int) TokenUsage::where('task_id', $run->task_id)->sum('total_tokens'),
                'hard_stop' => true,
                'period_key' => 'all',
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
            $this->periodUsage($query, $budget->period, $run->id);
            $common = [
                'budget_id' => $budget->id,
                'scope' => "{$budget->scope_type}:{$budget->period}",
                'hard_stop' => (bool) $budget->hard_stop,
                'period_key' => $this->periodKey($budget->period, $run->id),
            ];
            $constraints[] = [
                ...$common,
                'key' => "policy:{$budget->id}",
                'metric' => 'tokens',
                'limit' => (int) $budget->max_tokens,
                'used' => (int) (clone $query)->sum('total_tokens'),
            ];

            if ($budget->max_cost !== null) {
                $constraints[] = [
                    ...$common,
                    'key' => "policy:{$budget->id}",
                    'metric' => 'cost',
                    'limit' => (float) $budget->max_cost,
                    'used' => (float) (clone $query)->sum('total_cost'),
                ];
            }
        }

        return array_map(function (array $constraint) {
            $constraint['remaining'] = max(0, $constraint['limit'] - $constraint['used']);

            return $constraint;
        }, $constraints);
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

    private function periodUsage(Builder $query, string $period, int $runId): void
    {
        match ($period) {
            'day' => $query->where('recorded_at', '>=', now()->startOfDay()),
            'month' => $query->where('recorded_at', '>=', now()->startOfMonth()),
            'run' => $query->where('agent_run_id', $runId),
            default => null,
        };
    }

    private function periodKey(string $period, int $runId): string
    {
        return match ($period) {
            'day' => now()->format('Y-m-d'),
            'month' => now()->format('Y-m'),
            'run' => "run-{$runId}",
            default => 'all',
        };
    }
}
