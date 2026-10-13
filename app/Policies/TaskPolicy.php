<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;

class TaskPolicy
{
    public function view(User $user, Task $task): bool
    {
        return $user->hasPermissionIn($task->project->organization, Permission::ProjectsView);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasPermissionIn($project->organization, Permission::TasksManage);
    }

    /** Edit, move, comment, dependencies, workspace (Plan §95). */
    public function manage(User $user, Task $task): bool
    {
        return $user->hasPermissionIn($task->project->organization, Permission::TasksManage);
    }
}
