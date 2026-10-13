<?php

namespace App\Enums;

/**
 * Explicit permissions (Plan §76). Roles map to permissions in OrganizationRole;
 * policies only ever check permissions, never role names.
 */
enum Permission: string
{
    case ProjectsView = 'projects.view';
    case ProjectsCreate = 'projects.create';
    case ProjectsUpdate = 'projects.update';
    case ProjectsDelete = 'projects.delete';
    case MembersManage = 'members.manage';
    case SettingsManage = 'settings.manage';
    case ActivityView = 'activity.view';
    // Create, edit, move and comment on tasks (Plan §95).
    case TasksManage = 'tasks.manage';
    // Approve a release-ready task (Plan §56 human approval).
    case TasksApprove = 'tasks.approve';
    case AgentsView = 'agents.view';
    case AgentsManage = 'agents.manage';
    // Pair and revoke EAJ Local Runners (developers pair their own PCs).
    case RunnersManage = 'runners.manage';
    // Anyone doing the work can pull the emergency stop; only owners may resume (§57).
    case OperationsHalt = 'operations.halt';
    case OperationsResume = 'operations.resume';
}
