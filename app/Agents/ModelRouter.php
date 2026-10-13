<?php

namespace App\Agents;

use App\Models\Agent;
use App\Models\AiModel;
use App\Models\ModelRoute;

/** Provider-neutral routing: model names live in data, never in application code. */
class ModelRouter
{
    /** @return array{route: ModelRoute|null, primary: AiModel|null, fallback: AiModel|null} */
    public function resolve(Agent $agent, string $purpose = 'default', int $requiredContext = 0): array
    {
        $route = ModelRoute::query()
            ->where('organization_id', $agent->organization_id)
            ->where('enabled', true)
            ->where(function ($query) use ($agent) {
                $query->where('agent_role_id', $agent->agent_role_id)->orWhereNull('agent_role_id');
            })
            ->whereIn('purpose', [$purpose, 'default'])
            ->orderByRaw('agent_role_id is null')
            ->orderByRaw('purpose = ? desc', [$purpose])
            ->first();

        if (! $route) {
            return ['route' => null, 'primary' => null, 'fallback' => null];
        }

        $eligible = fn ($query) => $query
            ->where('organization_id', $agent->organization_id)
            ->where('enabled', true)
            ->when($requiredContext > 0, fn ($q) => $q->where(fn ($c) => $c->whereNull('max_context')->orWhere('max_context', '>=', $requiredContext)));

        $primary = $route->primary_model_id
            ? $eligible(AiModel::query())->find($route->primary_model_id)
            : $eligible(AiModel::query())->where('tier', $route->tier)->orderBy('id')->first();

        $fallback = $route->fallback_model_id
            ? $eligible(AiModel::query())->find($route->fallback_model_id)
            : $eligible(AiModel::query())->where('tier', $route->tier)->when($primary, fn ($q) => $q->whereKeyNot($primary->id))->orderBy('id')->first();

        return ['route' => $route, 'primary' => $primary, 'fallback' => $fallback];
    }
}
