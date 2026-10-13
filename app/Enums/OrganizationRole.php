<?php

namespace App\Enums;

/**
 * Organization roles (Plan §76). "Super Admin" is a platform-level flag on the
 * user (users.is_super_admin), not an organization role.
 */
enum OrganizationRole: string
{
    case Owner = 'owner';
    case ProjectManager = 'project_manager';
    case Developer = 'developer';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Organization Owner',
            self::ProjectManager => 'Project Manager',
            self::Developer => 'Developer',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::ProjectManager => [
                Permission::ProjectsView,
                Permission::ProjectsCreate,
                Permission::ProjectsUpdate,
                Permission::ActivityView,
                Permission::TasksManage,
                Permission::TasksApprove,
                Permission::AgentsView,
                Permission::AgentsManage,
                Permission::RunnersManage,
                Permission::OperationsHalt,
            ],
            self::Developer => [
                Permission::ProjectsView,
                Permission::ActivityView,
                Permission::TasksManage,
                Permission::AgentsView,
                Permission::RunnersManage,
                Permission::OperationsHalt,
            ],
            self::Viewer => [
                Permission::ProjectsView,
                Permission::ActivityView,
                Permission::AgentsView,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
