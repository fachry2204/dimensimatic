<?php

namespace Tests\Feature;

use App\Livewire\Automation\Dashboard;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
    }

    public function test_viewer_can_view_dashboard_but_not_manage_pages(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer)->get('/')->assertOk();
        $this->get('/mappings')->assertForbidden();
        $this->get('/sessions')->assertForbidden();
    }

    public function test_viewer_cannot_start_automation(): void
    {
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Livewire::test(Dashboard::class)->call('start')->assertForbidden();
    }

    public function test_admin_has_all_automation_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->assertTrue($admin->can('automation.run'));
        $this->assertTrue($admin->can('automation.retry'));
        $this->assertTrue($admin->can('mappings.manage'));
        $this->assertTrue($admin->can('sessions.manage'));
    }
}
