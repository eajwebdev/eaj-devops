<?php

namespace App\Support;

use App\Actions\Organizations\ProvisionPersonalOrganization;
use App\Models\Organization;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

/**
 * The organization the signed-in user is working in (request-scoped).
 *
 * Every tenant-owned query starts from this organization (Plan §75); route
 * bindings for tenant models resolve through it, so a record from another
 * organization is a 404, never a permission check on a leaked record.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    private ?int $resolvedForUserId = null;

    public function __construct(private readonly ProvisionPersonalOrganization $provision) {}

    public function get(): Organization
    {
        $user = Auth::user() ?? throw new AuthenticationException;

        // Never hand one user's organization to another, even if this instance outlives a request.
        if ($this->organization && $this->resolvedForUserId === $user->getKey()) {
            return $this->organization;
        }

        $this->resolvedForUserId = $user->getKey();

        return $this->organization = $this->provision->handle($user);
    }
}
