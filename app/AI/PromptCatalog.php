<?php

namespace App\AI;

use App\Models\AgentRole;
use App\Models\Organization;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\DB;

/**
 * Versioned prompts (§81): one planning prompt per role, implementation prompts
 * for the roles that write code, review prompts for the reviewers. Rolling back
 * is activating an earlier version.
 */
class PromptCatalog
{
    /** Roles whose agents change code (§10). */
    public const IMPLEMENTERS = ['BE', 'FE', 'DB', 'DO'];

    /** Review gates and their reviewers (§33: QA → Senior Review → Security). */
    public const REVIEWERS = ['qa' => 'QA', 'senior' => 'SR', 'security' => 'SE'];

    public static function key(string $role, ?string $kind = null): string
    {
        return 'agent.'.strtolower($role).($kind ? ".{$kind}" : '');
    }

    public function ensure(Organization $organization): void
    {
        foreach (AgentRole::all() as $role) {
            $this->seed($organization, self::key($role->key), "You are the {$role->name} specialist. Work only from the supplied context. Be concise and evidence-based. Never expose hidden reasoning. Never claim to have read source content, changed files, or run tests unless the context contains tool evidence. If evidence is absent, return planning advice and warnings.", $this->schema());

            if (in_array($role->key, self::IMPLEMENTERS, true)) {
                $this->seed($organization, self::key($role->key, 'implement'), $this->implementPrompt($role->name), $this->implementationSchema());
            }

            if (in_array($role->key, self::REVIEWERS, true)) {
                $this->seed($organization, self::key($role->key, 'review'), $this->reviewPrompt($role->name, array_search($role->key, self::REVIEWERS, true)), $this->reviewSchema());
            }
        }
    }

    public function active(Organization $organization, string $key): PromptVersion
    {
        $find = fn () => PromptVersion::whereBelongsTo($organization)->where('key', $key)->where('active', true)->latest('id')->first();

        if (! $prompt = $find()) {
            $this->ensure($organization);
            $prompt = $find() ?? throw new \DomainException("No prompt \"{$key}\" exists.");
        }

        return $prompt;
    }

    /** The planning prompt of a role. */
    public function activeFor(Organization $organization, string $role): PromptVersion
    {
        return $this->active($organization, self::key($role));
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

    /** Planning result (§35). @return array<string, mixed> */
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

    /**
     * Code changes as exact search/replace edits on files the agent was shown,
     * or whole new files. The Studio applies them; nothing here is trusted as done.
     *
     * @return array<string, mixed>
     */
    public function implementationSchema(): array
    {
        $strings = ['type' => 'array', 'items' => ['type' => 'string']];

        return $this->object([
            'task_id' => ['type' => 'string'],
            'agent' => ['type' => 'string'],
            'status' => ['type' => 'string', 'enum' => ['completed', 'blocked']],
            'summary' => ['type' => 'string'],
            'confidence' => ['type' => 'string', 'enum' => ['HIGH', 'MEDIUM', 'LOW']],
            'changes' => ['type' => 'array', 'items' => $this->object([
                'path' => ['type' => 'string'],
                'action' => ['type' => 'string', 'enum' => ['edit', 'create', 'delete']],
                'edits' => ['type' => 'array', 'items' => $this->object([
                    'search' => ['type' => 'string'],
                    'replace' => ['type' => 'string'],
                ])],
                'content' => ['type' => ['string', 'null']],
            ])],
            'tests_added' => $strings,
            'questions' => $strings,
            'warnings' => $strings,
        ]);
    }

    /** Review output (§36) with §98/§99 severities. @return array<string, mixed> */
    public function reviewSchema(): array
    {
        return $this->object([
            'task_id' => ['type' => 'string'],
            'agent' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'score' => ['type' => 'integer'],
            'confidence' => ['type' => 'string', 'enum' => ['HIGH', 'MEDIUM', 'LOW']],
            'issues' => ['type' => 'array', 'items' => $this->object([
                'severity' => ['type' => 'string', 'enum' => ['BLOCKER', 'MAJOR', 'MINOR', 'SUGGESTION', 'CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFORMATIONAL']],
                'file' => ['type' => ['string', 'null']],
                'line' => ['type' => ['integer', 'null']],
                'issue' => ['type' => 'string'],
                'recommendation' => ['type' => 'string'],
            ])],
            'acceptance_criteria' => ['type' => 'array', 'items' => $this->object([
                'criterion' => ['type' => 'string'],
                'met' => ['type' => 'boolean'],
                'evidence' => ['type' => 'string'],
            ])],
            'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
        ]);
    }

    private function implementPrompt(string $name): string
    {
        return <<<PROMPT
        You are the {$name}. You implement the task by returning file changes for the task workspace; the Studio applies them and its own tools then format, analyse, test and build the result.
        Rules:
        - Change only files listed in context.files with status "ok" and redacted=false, or create new files. Never touch files marked redacted: they hold credentials.
        - For "edit", each search must be text copied exactly from the current file (same whitespace), long enough to occur exactly once; replace is its new text. Edits apply in order.
        - For "create", give the full file content. For "delete", give no edits and null content.
        - Follow the project's existing style, structure and design system (§22). Keep the change as small as the task needs.
        - Do not add dependencies (§86). If one is truly needed, or the request is ambiguous, return status "blocked" with your questions and no changes.
        - Add or update tests for the behaviour you change and list them in tests_added.
        - In fix and changes mode, fix exactly the reported failures and review issues.
        - Never claim that tests or tools ran. Return only the object matching the schema.
        PROMPT;
    }

    private function reviewPrompt(string $name, string $gate): string
    {
        $focus = match ($gate) {
            'qa' => 'Check every acceptance criterion against the diff and the test results, and what is untested (edge cases, empty and error states). Severities: BLOCKER, MAJOR, MINOR, SUGGESTION.',
            'security' => 'Review the diff for: authentication, authorization, tenant isolation, role escalation, mass assignment, validation, SQL injection, XSS, CSRF, file upload, SSRF, unsafe redirects, rate limiting, sensitive logging, secret leakage, dependency risk (§74.1). Severities: CRITICAL, HIGH, MEDIUM, LOW, INFORMATIONAL.',
            default => 'Review correctness, design, maintainability, consistency with the existing code, error handling, concurrency and test quality. Severities: BLOCKER, MAJOR, MINOR, SUGGESTION.',
        };

        return <<<PROMPT
        You are the {$name}. You review the change to the task workspace from its diff, the task, the plan and the tool results in the context (§53 diff-first review).
        {$focus}
        Rules:
        - Report only problems you can point to in the diff or the supplied tool output; give file and line from the diff when you can, otherwise null.
        - Score 0-100 for how ready this change is. Tool results are facts; do not contradict them.
        - Do not rewrite the code yourself; say what must change in recommendation.
        - Return only the object matching the schema.
        PROMPT;
    }

    /**
     * Strict-mode object: every property required, nothing extra.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function object(array $properties): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'required' => array_keys($properties), 'properties' => $properties];
    }

    /** @param array<string, mixed> $schema */
    private function seed(Organization $organization, string $key, string $prompt, array $schema): void
    {
        PromptVersion::firstOrCreate(
            ['organization_id' => $organization->id, 'key' => $key, 'version' => '1.0.0'],
            ['system_prompt' => $prompt, 'output_schema' => $schema, 'active' => true, 'published_at' => now()],
        );
    }
}
