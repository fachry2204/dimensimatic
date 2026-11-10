<?php

namespace Tests\Feature;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        $this->actingAs($user)->get('/')->assertOk()->assertSee('SoundOn Release Automation');
    }

    public function test_run_status_is_cast_to_enum(): void
    {
        $run = AutomationRun::create(['status' => 'queued']);
        $this->assertSame(AutomationRunStatus::Queued, $run->status);
    }

    public function test_dashboard_shows_release_platform_progress(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Viewer');
        $run = AutomationRun::create(['status' => 'running', 'started_at' => now()]);
        ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-1001',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/SF-1001',
            'soundon_draft_id' => 'DRAFT-2001',
            'soundon_draft_url' => 'https://www.soundon.global/releases/DRAFT-2001',
            'idempotency_key' => 'soundfresh:SF-1001:soundon',
            'release_title' => 'Rilisan Pengujian',
            'status' => 'running',
            'checkpoint' => 'draft_saved',
        ]);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Pending Dimensi Suara')
            ->assertSee('Berhasil upload ke SoundOn')
            ->assertSee('Gagal upload ke SoundOn')
            ->assertSee('Monitor rilisan')
            ->assertSee('Rilisan Pengujian')
            ->assertSee('Sudah diambil')
            ->assertSee('Draft tersimpan')
            ->assertSee('Progress');
    }
}
