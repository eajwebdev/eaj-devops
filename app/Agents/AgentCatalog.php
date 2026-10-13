<?php

namespace App\Agents;

use App\Enums\AgentStatus;
use App\Enums\ModelTier;
use App\Models\Agent;
use App\Models\AgentRole;
use App\Models\ModelRoute;
use App\Models\Organization;
use App\Models\Skill;
use App\Models\SkillVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Creates the versioned, editable starting team described by Plan Section 10. */
class AgentCatalog
{
    /** @return array<string, array{name: string, description: string, responsibilities: list<string>, may_implement: bool, budget: int, tier: ModelTier}> */
    private function definitions(): array
    {
        return [
            'PM' => ['name' => 'Project Manager', 'description' => 'Plans work, manages dependencies and selects the next specialist.', 'responsibilities' => ['Understand goals', 'Create task breakdowns', 'Track dependencies and blockers', 'Summarize progress'], 'may_implement' => false, 'budget' => 5000, 'tier' => ModelTier::Standard],
            'BA' => ['name' => 'Business Analyst', 'description' => 'Turns requests into testable requirements and business rules.', 'responsibilities' => ['Define requirements', 'Write acceptance criteria', 'Identify ambiguity and edge cases'], 'may_implement' => false, 'budget' => 5000, 'tier' => ModelTier::Fast],
            'SA' => ['name' => 'Solution Architect', 'description' => 'Owns boundaries, integrations, scalability and architecture decisions.', 'responsibilities' => ['Analyze architecture', 'Review security and concurrency', 'Create significant ADRs'], 'may_implement' => false, 'budget' => 10000, 'tier' => ModelTier::Advanced],
            'UX' => ['name' => 'UI/UX Designer', 'description' => 'Designs accessible, responsive flows that fit the existing product language.', 'responsibilities' => ['Design user flows', 'Define component hierarchy', 'Cover responsive and empty states', 'Review accessibility'], 'may_implement' => false, 'budget' => 12000, 'tier' => ModelTier::Standard],
            'FE' => ['name' => 'Senior Frontend Developer', 'description' => 'Implements typed, accessible user interfaces and frontend tests.', 'responsibilities' => ['Implement frontend', 'Integrate APIs', 'Handle forms and state', 'Write frontend tests'], 'may_implement' => true, 'budget' => 30000, 'tier' => ModelTier::Standard],
            'BE' => ['name' => 'Senior Backend Developer', 'description' => 'Implements services, APIs, authorization, queues and backend tests.', 'responsibilities' => ['Implement backend', 'Enforce authorization', 'Use transactions and queues safely', 'Write backend tests'], 'may_implement' => true, 'budget' => 30000, 'tier' => ModelTier::Standard],
            'DB' => ['name' => 'Database Engineer', 'description' => 'Protects schema integrity, query performance and concurrency.', 'responsibilities' => ['Design schemas and indexes', 'Review locks and transactions', 'Keep migrations backward compatible'], 'may_implement' => true, 'budget' => 10000, 'tier' => ModelTier::Advanced],
            'QA' => ['name' => 'QA Engineer', 'description' => 'Produces deterministic evidence across unit, integration, API and browser tests.', 'responsibilities' => ['Plan acceptance tests', 'Run regression tests', 'Reproduce failures', 'Report evidence'], 'may_implement' => true, 'budget' => 12000, 'tier' => ModelTier::Standard],
            'SE' => ['name' => 'Security Engineer', 'description' => 'Reviews authorization, isolation, secrets, dependencies and OWASP risks.', 'responsibilities' => ['Review authentication and authorization', 'Verify tenant isolation', 'Protect secrets', 'Assess dependency risk'], 'may_implement' => false, 'budget' => 8000, 'tier' => ModelTier::Advanced],
            'SR' => ['name' => 'Senior Code Reviewer', 'description' => 'Independently reviews diffs, design quality, tests and maintainability.', 'responsibilities' => ['Review diffs', 'Score quality', 'Check architecture and performance', 'Return actionable findings'], 'may_implement' => false, 'budget' => 8000, 'tier' => ModelTier::Advanced],
            'DO' => ['name' => 'DevOps Engineer', 'description' => 'Owns CI/CD, workers, runtime configuration, monitoring and rollback readiness.', 'responsibilities' => ['Maintain CI/CD', 'Configure workers', 'Validate health checks', 'Prepare rollback paths'], 'may_implement' => true, 'budget' => 15000, 'tier' => ModelTier::Standard],
            'RM' => ['name' => 'Release Manager', 'description' => 'Verifies exact-SHA gates and approvals before a release is declared ready.', 'responsibilities' => ['Verify required gates', 'Check migration safety', 'Confirm approvals', 'Complete release checklist'], 'may_implement' => false, 'budget' => 8000, 'tier' => ModelTier::Fast],
        ];
    }

    public function ensure(Organization $organization): void
    {
        DB::transaction(function () use ($organization) {
            foreach ($this->definitions() as $key => $definition) {
                $role = AgentRole::updateOrCreate(
                    ['key' => $key],
                    [
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'responsibilities' => $definition['responsibilities'],
                        'may_implement' => $definition['may_implement'],
                    ],
                );

                $agent = Agent::firstOrCreate(
                    ['organization_id' => $organization->id, 'slug' => Str::slug($definition['name'])],
                    [
                        'agent_role_id' => $role->id,
                        'name' => $definition['name'],
                        'status' => AgentStatus::Idle,
                        'token_budget' => $definition['budget'],
                        'retry_limit' => (int) config('eaj.limits.max_attempts', 3),
                        'max_wall_seconds' => (int) config('eaj.limits.max_step_seconds', 2700),
                    ],
                );

                $skill = Skill::firstOrCreate(
                    ['organization_id' => $organization->id, 'key' => Str::slug($definition['name'])],
                    ['name' => $definition['name'], 'description' => $definition['description']],
                );

                $version = SkillVersion::firstOrCreate(
                    ['skill_id' => $skill->id, 'version' => '1.0.0'],
                    [
                        'supported_frameworks' => [],
                        'supported_versions' => [],
                        'responsibilities' => $definition['responsibilities'],
                        'rules' => ['Use deterministic evidence before claims', 'Respect the existing project architecture', 'Keep handoffs concise and structured'],
                        'forbidden_actions' => ['Expose hidden reasoning', 'Bypass an approval or quality gate', 'Write outside the task workspace'],
                        'tools' => [],
                        'validation_expectations' => ['Return evidence for completed work', 'Report uncertainty and blockers explicitly'],
                        'references' => ['EAJ_AI_Dev_Studio_Master_Plan.md'],
                        'output_schema' => ['type' => 'object', 'required' => ['summary', 'evidence']],
                        'published_at' => now(),
                    ],
                );

                DB::table('agent_skills')->insertOrIgnore([
                    'agent_id' => $agent->id,
                    'skill_id' => $skill->id,
                    'skill_version_id' => $version->id,
                    'enabled' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                ModelRoute::firstOrCreate(
                    ['organization_id' => $organization->id, 'route_key' => strtolower($key).'.default'],
                    ['agent_role_id' => $role->id, 'purpose' => 'default', 'tier' => $definition['tier']],
                );
            }
        });
    }
}
