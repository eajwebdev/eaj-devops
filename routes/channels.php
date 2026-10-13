<?php

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Private tenant channels. Socket authorization repeats the same membership
// and permission checks as the HTTP catch-up API (Plan Sections 48.6, 62, 75).
Broadcast::channel('organization.{organizationId}', function (User $user, int $organizationId) {
    $organization = Organization::find($organizationId);

    return $organization && $user->hasPermissionIn($organization, Permission::ActivityView);
});

Broadcast::channel('project.{projectId}', function (User $user, int $projectId) {
    $project = Project::with('organization')->find($projectId);

    return $project && $user->hasPermissionIn($project->organization, Permission::ProjectsView);
});
