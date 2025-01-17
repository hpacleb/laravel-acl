<?php

namespace Yajra\Acl\Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Yajra\Acl\Models\Permission;
use Yajra\Acl\Models\Role;

class PermissionTest extends TestCase
{
    use DatabaseTransactions;

    /** @test */
    public function it_can_create_resource_permissions()
    {
        $permissions = Permission::createResource('Users');
        $this->assertCount(5, $permissions);

        $this->assertTrue(Permission::findBySlug('viewAny-users')->exists());
        $this->assertTrue(Permission::findBySlug('view-users')->exists());
        $this->assertTrue(Permission::findBySlug('create-users')->exists());
        $this->assertTrue(Permission::findBySlug('update-users')->exists());
        $this->assertTrue(Permission::findBySlug('delete-users')->exists());
    }

    /** @test */
    public function it_refreshes_permissions_policies_cache()
    {
        $permissions = cache('permissions.policies');
        $this->assertCount(5, $permissions);

        Permission::createResource('Posts');

        $permissions = cache('permissions.policies');
        $this->assertCount(10, $permissions);
    }

    /** @test */
    public function it_can_interacts_with_role()
    {
        /** @var Permission $permission */
        $permission = Permission::first();

        $this->assertTrue($permission->hasRole('admin'));

        $permission->attachRole($userRole = $this->createRole('user'));
        $this->assertTrue($permission->hasRole('user'));

        $permission->attachRoleBySlug('registered');
        $this->assertTrue($permission->hasRole('registered'));

        $permission->revokeRole($userRole);
        $this->assertFalse($permission->hasRole('user'));

        $permission->revokeRoleBySlug('registered');
        $this->assertFalse($permission->hasRole('registered'));

        $permission->revokeAllRoles();
        $this->assertCount(0, $permission->roles);

        $permission->syncRoles([1]);
        $this->assertCount(1, $permission->roles);
    }
}
