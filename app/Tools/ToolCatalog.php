<?php

namespace App\Tools;

use App\Enums\ToolKind;
use App\Models\Project;
use App\Models\ProjectTool;
use App\Models\User;
use App\Reliability\AuditLogger;
use App\Reliability\EventRecorder;
use App\Security\SecretRedactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The project's approved command set (Plan §84). The runner reports which
 * tools the project really has after every scan (§34); the owner decides which
 * agents may run. A tool that disappears is kept, marked unavailable, so the
 * owner's choice survives a temporary change.
 */
class ToolCatalog
{
    public function __construct(
        private readonly EventRecorder $events,
        private readonly AuditLogger $audit,
        private readonly SecretRedactor $redactor,
    ) {}

    /**
     * @param  array<int, mixed>  $reported  the runner's scan result "tools"
     * @return array{tools: int, added: list<string>, removed: list<string>}
     */
    public function sync(Project $project, array $reported): array
    {
        $tools = collect($reported)
            ->filter(fn ($tool) => is_array($tool) && $this->valid($tool))
            ->keyBy('key');

        $existing = $project->tools()->get()->keyBy('key');
        $added = [];

        foreach ($tools as $key => $tool) {
            $kind = ToolKind::from($tool['kind']);
            $row = $existing->get($key) ?? new ProjectTool;
            $isNew = ! $row->exists;

            $row->forceFill([
                'project_id' => $project->id,
                'key' => $key,
                'kind' => $kind,
                'ecosystem' => $tool['ecosystem'],
                'label' => Str::limit((string) $tool['label'], 120, ''),
                'command' => Str::limit($this->redactor->redactText((string) $tool['command']), 500, ''),
                'parser' => $tool['parser'],
                'supports_fix' => (bool) ($tool['supports_fix'] ?? false),
                'scoped' => (bool) ($tool['scoped'] ?? false),
                'filterable' => (bool) ($tool['filterable'] ?? false),
                'blocked' => isset($tool['blocked']) && is_string($tool['blocked']) ? Str::limit($this->redactor->redactText($tool['blocked']), 500, '') : null,
                'enabled' => $isNew ? $kind->enabledByDefault() : $row->enabled,
                'available' => true,
                'detected_at' => now(),
            ])->save();

            if ($isNew) {
                $added[] = $key;
            }
        }

        $removed = $existing->keys()->diff($tools->keys())->values()->all();
        if ($removed !== []) {
            $project->tools()->whereIn('key', $removed)->update(['available' => false, 'updated_at' => now()]);
        }

        if ($added !== [] || $removed !== []) {
            $this->events->record('project.tools.updated', ['added' => $added, 'removed' => $removed, 'tools' => $tools->count()], $project);
        }

        return ['tools' => $tools->count(), 'added' => $added, 'removed' => $removed];
    }

    public function setEnabled(ProjectTool $tool, bool $enabled, User $user): void
    {
        if ($tool->enabled === $enabled) {
            return;
        }

        DB::transaction(function () use ($tool, $enabled, $user) {
            $tool->forceFill(['enabled' => $enabled])->save();
            $project = $tool->project;

            $this->events->record('project.tool.changed', ['tool' => $tool->toClientArray()], $project, actor: $user);
            $this->audit->log($enabled ? 'tool.enabled' : 'tool.disabled', $user, $project->organization, $project, $tool, ['key' => $tool->key, 'command' => $tool->command]);
        });
    }

    /**
     * Only well-formed entries from the runner's own catalog are stored.
     *
     * @param  array<string, mixed>  $tool
     */
    private function valid(array $tool): bool
    {
        return is_string($tool['key'] ?? null) && preg_match('/^[a-z][a-z0-9]*\.[a-z0-9-]+$/', $tool['key'])
            && is_string($tool['kind'] ?? null) && ToolKind::tryFrom($tool['kind']) !== null
            && in_array($tool['ecosystem'] ?? null, ['php', 'js', 'python'], true)
            && is_string($tool['label'] ?? null) && is_string($tool['command'] ?? null)
            && is_string($tool['parser'] ?? null) && preg_match('/^[a-z-]{1,32}$/', $tool['parser']);
    }
}
