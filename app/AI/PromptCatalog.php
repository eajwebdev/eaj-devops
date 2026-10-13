<?php

namespace App\AI;

use App\Models\AgentRole;
use App\Models\Organization;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\DB;

class PromptCatalog
{
    public function ensure(Organization $organization): void
    {
        foreach (AgentRole::all() as $role) {
            PromptVersion::firstOrCreate(
                ['organization_id' => $organization->id, 'key' => 'agent.'.strtolower($role->key), 'version' => '1.0.0'],
                [
                    'system_prompt' => "You are the {$role->name} specialist. Work only from the supplied context. Be concise and evidence-based. Never expose hidden reasoning. Never claim to have read source content, changed files, or run tests unless the context contains tool evidence. If evidence is absent, return planning advice and warnings.",
                    'output_schema' => $this->schema(),
                    'active' => true,
                    'published_at' => now(),
                ],
            );
        }
    }

    public function activeFor(Organization $organization, string $role): PromptVersion
    {
        $key = 'agent.'.strtolower($role);
        $prompt = PromptVersion::whereBelongsTo($organization)->where('key', $key)->where('active', true)->latest('id')->first();
        if (! $prompt) {
            $this->ensure($organization);
            $prompt = PromptVersion::whereBelongsTo($organization)->where('key', $key)->where('active', true)->latest('id')->firstOrFail();
        }

        return $prompt;
    }

    public function activate(PromptVersion $prompt): void
    {
        // Two queries, not save(): rolling back to a version whose in-memory copy still says
        // "active" would otherwise be a no-op after the bulk deactivation, leaving no active prompt.
        DB::transaction(function () use ($prompt) {
            PromptVersion::where('organization_id', $prompt->organization_id)->where('key', $prompt->key)->whereKeyNot($prompt->id)->update(['active' => false]);
            PromptVersion::whereKey($prompt->id)->update(['active' => true, 'published_at' => $prompt->published_at ?? now()]);
        });
        $prompt->refresh();
    }

    /** @return array<string, mixed> */
    public function schema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['task_id', 'agent', 'status', 'summary', 'files_read', 'files_modified', 'tests_added', 'tests_passed', 'tests_failed', 'warnings', 'ready_for_review'],
            'properties' => [
                'task_id' => ['type' => 'string'],
                'agent' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['completed', 'blocked']],
                'summary' => ['type' => 'string'],
                'files_read' => $strings,
                'files_modified' => $strings,
                'tests_added' => $strings,
                'tests_passed' => ['type' => 'integer'],
                'tests_failed' => ['type' => 'integer'],
                'warnings' => $strings,
                'ready_for_review' => ['type' => 'boolean'],
            ],
        ];
    }
}
