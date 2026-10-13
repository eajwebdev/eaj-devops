<?php

namespace App\Costs;

use App\Agents\ModelRouter;
use App\Models\Task;

class CostEstimator
{
    public function __construct(
        private readonly ModelRouter $router,
        private readonly CostCalculator $calculator,
    ) {}

    /** @return array{amount: float, currency: string, priced_models: int, total_models: int}|null */
    public function forTask(Task $task): ?array
    {
        $tokens = (int) ($task->impact['token_estimate'] ?? 0);
        $roles = collect($task->impact['agents'] ?? [])->pluck('role')->filter()->unique();
        if ($tokens <= 0 || $roles->isEmpty()) {
            return null;
        }

        $agents = $task->project->organization->agents()->with('role')->whereHas('role', fn ($query) => $query->whereIn('key', $roles))->get();
        $models = $agents->map(fn ($agent) => $this->router->resolve($agent, $agent->role->may_implement ? 'implementation' : 'default')['primary'])
            ->filter()->unique('id')->values();
        if ($models->isEmpty()) {
            return null;
        }

        $input = (int) round($tokens * 0.75);
        $output = $tokens - $input;
        $costs = $models->map(fn ($model) => $this->calculator->calculate($model, $input, 0, $output))->filter();
        if ($costs->isEmpty()) {
            return null;
        }

        return [
            'amount' => round((float) $costs->avg('total'), 6),
            'currency' => 'USD',
            'priced_models' => $costs->count(),
            'total_models' => $models->count(),
        ];
    }
}
