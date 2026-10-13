<?php

namespace Tests\Unit;

use App\Enums\OrganizationRole;
use App\Enums\Permission;
use PHPUnit\Framework\TestCase;

class OrganizationRoleTest extends TestCase
{
    public function test_owner_has_every_permission()
    {
        foreach (Permission::cases() as $permission) {
            $this->assertTrue(OrganizationRole::Owner->can($permission), $permission->value);
        }
    }

    public function test_only_owners_can_resume_operations_or_manage_members()
    {
        foreach ([OrganizationRole::ProjectManager, OrganizationRole::Developer, OrganizationRole::Viewer] as $role) {
            $this->assertFalse($role->can(Permission::OperationsResume), $role->value);
            $this->assertFalse($role->can(Permission::MembersManage), $role->value);
        }
    }

    public function test_viewers_are_read_only()
    {
        $this->assertSame(
            [Permission::ProjectsView, Permission::ActivityView, Permission::AgentsView],
            OrganizationRole::Viewer->permissions(),
        );
    }
}
