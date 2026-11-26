<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReleaseStatusPermissionUpgradeTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_restores_missing_permission_without_replacing_existing_access(): void
    {
        $this->seed(AccessControlSeeder::class);
        Permission::findByName('release-status.view')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $custom = Role::create(['name' => 'Custom', 'guard_name' => 'web']);
        $custom->givePermissionTo('automation.view');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $migration = require database_path('migrations/2026_11_26_000001_restore_missing_release_status_permission.php');
        $migration->up();

        foreach (['Admin', 'Staff', 'Operator', 'Viewer'] as $name) {
            $this->assertTrue(Role::findByName($name)->hasPermissionTo('release-status.view'));
        }
        $this->assertTrue($custom->fresh()->hasPermissionTo('automation.view'));
        $this->assertFalse($custom->fresh()->hasPermissionTo('release-status.view'));
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('data-can-view-releases="1"', false);
        $this->get('/release-status')->assertOk();

        Role::findByName('Viewer')->revokePermissionTo('release-status.view');
        $migration->up();
        $this->assertFalse(Role::findByName('Viewer')->hasPermissionTo('release-status.view'));
    }
}
