<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrganizationRole;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * is_super_admin and current_organization_id are deliberately not fillable
     * (mass-assignment protection, Plan §74.2 check 4).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_members')
            ->using(OrganizationMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function roleIn(Organization $organization): ?OrganizationRole
    {
        $role = $this->organizations()
            ->whereKey($organization->getKey())
            ->first()
            ?->pivot
            ?->role;

        return $role instanceof OrganizationRole ? $role : ($role ? OrganizationRole::from($role) : null);
    }

    public function belongsToOrganization(Organization $organization): bool
    {
        return $this->roleIn($organization) !== null;
    }

    /**
     * Super admins can act in any organization; everyone else needs a role
     * in that organization granting the permission.
     */
    public function hasPermissionIn(Organization $organization, Permission $permission): bool
    {
        if ($this->is_super_admin) {
            return true;
        }

        return $this->roleIn($organization)?->can($permission) ?? false;
    }

    public function switchOrganization(Organization $organization): void
    {
        $this->forceFill(['current_organization_id' => $organization->getKey()])->save();
        $this->setRelation('currentOrganization', $organization);
    }
}
