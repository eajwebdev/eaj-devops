<?php

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\OutboxEvent;
use App\Reliability\SystemHalt;
use App\Support\CurrentOrganization;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Kept small (Plan §92): live data arrives through the event stream.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $organization = $user ? app(CurrentOrganization::class)->get() : null;
        $role = $organization ? $user->roleIn($organization) : null;
        $permissions = $user?->is_super_admin ? Permission::cases() : ($role?->permissions() ?? []);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'demoMode' => (bool) config('eaj.demo_mode'),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at,
                    'is_super_admin' => $user->is_super_admin,
                ] : null,
            ],
            'organization' => $organization ? [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'is_demo' => $organization->is_demo,
                'role' => $role?->label() ?? ($user->is_super_admin ? 'Super Admin' : null),
                'permissions' => array_map(fn (Permission $p) => $p->value, $permissions),
            ] : null,
            'organizations' => fn () => $user
                ? $user->organizations()->orderBy('name')->get(['organizations.id', 'name', 'slug'])
                    ->map(fn (Organization $o) => $o->only('id', 'name', 'slug'))
                : [],
            'operations' => $organization ? $this->operations($organization) : null,
            // Where the client-side event stream starts: everything after this is "new".
            'eventCursor' => fn () => $organization
                ? (int) OutboxEvent::where('organization_id', $organization->id)->max('id')
                : 0,
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }

    /**
     * @return array{halted: bool, scope: string|null, reason: string|null, halted_at: string|null}
     */
    private function operations(Organization $organization): array
    {
        $halt = app(SystemHalt::class);
        $global = $halt->state(null);
        $state = $global ?? $halt->state($organization->id);

        return [
            'halted' => $state !== null,
            'scope' => $state ? ($global ? 'global' : 'organization') : null,
            'reason' => $state['reason'] ?? null,
            'halted_at' => $state['halted_at'] ?? null,
        ];
    }
}
