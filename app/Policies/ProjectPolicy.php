<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermissionIn($organization, Permission::ProjectsView);
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasPermissionIn($project->organization, Permission::ProjectsView);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermissionIn($organization, Permission::ProjectsCreate);
    }

    public function update(User $user, Project $project): bool
    {
        return $user->hasPermissionIn($project->organization, Permission::ProjectsUpdate);
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->hasPermissionIn($project->organization, Permission::ProjectsDelete);
    }
}
