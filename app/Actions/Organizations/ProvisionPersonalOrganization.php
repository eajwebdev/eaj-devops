<?php

namespace App\Actions\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use App\Reliability\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Guarantees a user always has a valid current organization. Idempotent:
 * returns the existing current/first organization when there is one.
 */
class ProvisionPersonalOrganization
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(User $user): Organization
    {
        $current = $user->currentOrganization;

        if ($current && $user->belongsToOrganization($current)) {
            return $current;
        }

        $first = $user->organizations()->orderBy('organizations.id')->first();

        if ($first) {
            $user->switchOrganization($first);

            return $first;
        }

        return DB::transaction(function () use ($user) {
            $name = trim($user->name)."'s Organization";

            $organization = Organization::create([
                'name' => $name,
                'slug' => Organization::uniqueSlugFor($user->name),
            ]);

            $organization->addMember($user, OrganizationRole::Owner);
            $user->switchOrganization($organization);

            $this->audit->log('organization.created', $user, $organization, subject: $organization);

            return $organization;
        });
    }
}
