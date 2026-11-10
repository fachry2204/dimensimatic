<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Automation\UserManagement;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

final class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_staff_user_with_release_status_only_access(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(UserManagement::class)
            ->set('name', 'Staff Status')
            ->set('username', 'staff.status')
            ->set('password', 'secret123')
            ->set('role', 'Staff')
            ->call('createUser')
            ->assertHasNoErrors()
            ->assertSee('User Staff Status berhasil ditambahkan.');

        $staff = User::query()->where('username', 'staff.status')->sole();
        $this->assertTrue(Hash::check('secret123', $staff->password));
        $this->assertTrue($staff->hasRole('Staff'));
        $this->assertTrue($staff->can('release-status.view'));
        $this->assertFalse($staff->can('automation.view'));
        $this->assertFalse($staff->can('automation.run'));
    }

    public function test_admin_can_change_existing_user_role(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(UserManagement::class)
            ->set('userRoles.'.$user->id, 'Staff')
            ->call('updateRole', $user->id)
            ->assertHasNoErrors()
            ->assertSee('Role user '.$user->name.' berhasil diubah menjadi Staff.');

        $this->assertTrue($user->fresh()->hasRole('Staff'));
        $this->assertFalse($user->fresh()->can('automation.run'));
    }

    public function test_admin_cannot_demote_own_active_account(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        Livewire::test(UserManagement::class)
            ->set('userRoles.'.$admin->id, 'Staff')
            ->call('updateRole', $admin->id)
            ->assertHasErrors('userRoles.'.$admin->id);

        $this->assertTrue($admin->fresh()->hasRole('Admin'));
    }

    public function test_staff_can_only_open_release_status(): void
    {
        $this->seed(AccessControlSeeder::class);
        $staff = User::factory()->create();
        $staff->assignRole('Staff');

        $this->actingAs($staff)
            ->get('/release-status')->assertOk();
        $this->get('/')->assertForbidden();
        $this->get('/sessions')->assertForbidden();
        $this->get('/users')->assertForbidden();
    }

    public function test_staff_navigation_only_contains_release_status(): void
    {
        $this->seed(AccessControlSeeder::class);
        $staff = User::factory()->create(['name' => 'Staff Status']);
        $staff->assignRole('Staff');

        $this->actingAs($staff)
            ->get('/release-status')
            ->assertOk()
            ->assertSee('data-can-view-dashboard="0"', false)
            ->assertSee('data-can-manage-users="0"', false);
    }
}
