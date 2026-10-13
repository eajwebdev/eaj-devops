<?php

namespace App\AI;

use App\Models\Agent;
use App\Models\Project;
use App\Models\SkillVersion;
use Illuminate\Support\Collection;

class SkillLoader
{
    /** @return Collection<int, SkillVersion> */
    public function for(Agent $agent, Project $project): Collection
    {
        $frameworks = $this->frameworks($project->connection?->detected_stack ?? []);

        return SkillVersion::query()
            ->select('skill_versions.*')
            ->join('agent_skills', 'agent_skills.skill_version_id', '=', 'skill_versions.id')
            ->join('skills', 'skills.id', '=', 'skill_versions.skill_id')
            ->where('agent_skills.agent_id', $agent->id)
            ->where('agent_skills.enabled', true)
            ->where('skills.enabled', true)
            ->where('skill_versions.active', true)
            ->get()
            ->filter(function (SkillVersion $version) use ($frameworks) {
                $supported = array_map('strtolower', $version->supported_frameworks ?? []);

                return $supported === [] || array_intersect($supported, $frameworks) !== [];
            })->values();
    }

    /** @return list<string> */
    private function frameworks(array $stack): array
    {
        $values = [];
        array_walk_recursive($stack, function ($value, $key) use (&$values) {
            if (is_string($value) && in_array(strtolower((string) $key), ['framework', 'runtime', 'language', 'name'], true)) {
                $values[] = strtolower($value);
            }
        });

        return array_values(array_unique($values));
    }
}
