<?php

namespace App\Reliability;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

/**
 * Append-only audit trail (Plan §59).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        ?User $user = null,
        ?Organization $organization = null,
        ?Project $project = null,
        ?Model $subject = null,
        array $metadata = [],
    ): AuditLog {
        return AuditLog::forceCreate([
            'organization_id' => $organization?->getKey() ?? $project?->organization_id,
            'project_id' => $project?->getKey(),
            'user_id' => $user?->getKey() ?? auth()->id(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata ?: null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
            'request_id' => Context::get('request_id'),
        ]);
    }
}
