<?php

namespace App\AI;

use App\Agents\ModelRouter;
use App\Models\Agent;
use App\Models\Task;

/**
 * Specialist routing (§2.3): only the agents a task needs are activated, in the
 * order their work depends on each other. Reviewers (SR, SE) act on code, so
 * they are skipped until there is a diff to review.
 */
class SpecialistRouter
{
    public const PURPOSE = 'planning';

    /** Dependency order: requirements → architecture → design → data → server → client → tests. */
    private const ORDER = ['BA', 'SA', 'UX', 'DB', 'BE', 'FE', 'QA', 'DO', 'SE', 'SR', 'RM', 'PM'];

    /** Roles whose job is to review an implementation, not to plan one. */
    private const REVIEWERS = ['SR', 'SE'];

    public function __construct(private readonly ModelRouter $models) {}

    /**
     * @return array{
     *     activate: list<array{role: string, reason: string, agent: Agent}>,
     *     skipped: list<array{role: string, reason: string}>
     * }
     */
    public function route(Task $task): array
    {
        $recommended = collect($task->impact['agents'] ?? [])
            ->filter(fn ($item) => is_array($item) && is_string($item['role'] ?? null))
            ->unique('role')
            ->sortBy(fn ($item) => array_search($item['role'], self::ORDER, true) === false ? 99 : array_search($item['role'], self::ORDER, true))
            ->values();

        $agents = Agent::query()
            ->where('organization_id', $task->organization_id)
            ->with('role')
            ->get()
            ->keyBy(fn (Agent $agent) => $agent->role->key);

        $activate = [];
        $skipped = [];

        foreach ($recommended as $item) {
            $role = $item['role'];
            $agent = $agents->get($role);

            $why = match (true) {
                in_array($role, self::REVIEWERS, true) => 'Reviews the implementation — runs once there is a diff (Phase 8).',
                ! $agent => 'No agent with this role in the organization.',
                ! $agent->enabled => "{$agent->name} is disabled.",
                ! $this->models->resolve($agent, self::PURPOSE)['primary'] => "No AI model is routed to {$agent->name}. Add one under AI Team → Models and routes.",
                default => null,
            };

            if ($why) {
                $skipped[] = ['role' => $role, 'reason' => $why];
            } else {
                $activate[] = ['role' => $role, 'reason' => (string) ($item['reason'] ?? ''), 'agent' => $agent];
            }
        }

        return ['activate' => $activate, 'skipped' => $skipped];
    }
}
