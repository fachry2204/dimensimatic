<?php

namespace Tests\Feature;

use App\Livewire\Automation\Dashboard;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardPollingTest extends TestCase
{
    use RefreshDatabase;

    public function test_pending_button_reaches_collection_with_readable_credentials(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        \App\Models\AutomationAccount::create([
            'platform' => 'soundfresh', 'name' => 'Dimensimatic', 'status' => 'active',
            'email_encrypted' => 'probe@example.com', 'password_encrypted' => 'test-password',
            'session_state_encrypted' => ['cookies' => [], 'origins' => []],
            'last_authenticated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => ['items' => []]]),
        ]);
        Livewire::test(Dashboard::class)->call('start')
            ->assertSet('operationError', null)->assertSet('configurationError', null);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) =>
            str_ends_with($request->url(), '/v1/soundfresh/pending')
            && $request['action'] === 'list_pending_releases');
        $this->assertDatabaseHas('automation_runs', ['status' => 'completed_no_pending']);
    }

    public function test_start_queue_reports_missing_credentials_without_a_type_error(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        Livewire::test(Dashboard::class)->call('startQueueWorker')
            ->assertSet('configurationError', 'Credential Dimensi Suara belum dikonfigurasi pada halaman Pengaturan.')
            ->assertSet('showWorkerOperationModal', false);
    }

    public function test_start_checker_reports_missing_credentials_without_false_success(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        Livewire::test(Dashboard::class)->call('startCheckWorker')
            ->assertSet('showWorkerOperationModal', false)
            ->assertSee('Credential Dimensi Suara belum lengkap');
    }

    public function test_poll_renders_releases_inserted_after_initial_empty_screen(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        $run = AutomationRun::create(['status' => 'queued', 'started_at' => now()]);
        $screen = Livewire::test(Dashboard::class)->set('monitorRunId', $run->id)
            ->assertSee('Daftar akan tampil otomatis tanpa refresh.');
        ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'poll-test',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/poll-test',
            'idempotency_key' => 'poll-test', 'release_title' => 'Rilisan Baru Otomatis',
            'status' => 'queued', 'soundfresh_workflow_status' => 'pending',
        ]);
        $run->update(['pending_found' => 1, 'summary_json' => ['pending_release_ids' => ['poll-test']]]);
        $screen->call('refreshMonitor')->assertSee('Rilisan Baru Otomatis')
            ->assertDontSee('Daftar akan tampil otomatis tanpa refresh.')
            ->assertSeeHtml('wire:poll.2s.keep-alive="refreshMonitor"');
    }

    public function test_poll_closes_a_collected_run_after_duplicate_checks_finish(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        $run = AutomationRun::create([
            'status' => 'queued',
            'started_at' => now(),
            'pending_found' => 1,
            'summary_json' => [
                'collection_pending' => false,
                'duplicate_check_completed' => true,
                'pending_release_ids' => ['finished-check'],
            ],
        ]);
        ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'finished-check',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/finished-check',
            'idempotency_key' => 'finished-check',
            'release_title' => 'Rilisan Siap Dipilih',
            'status' => 'queued',
            'soundfresh_workflow_status' => 'pending',
        ]);

        Livewire::test(Dashboard::class)->set('monitorRunId', $run->id)
            ->call('refreshMonitor')
            ->assertSee('Rilisan Siap Dipilih');

        $this->assertDatabaseHas('automation_runs', [
            'id' => $run->id,
            'status' => 'completed',
        ]);
        $this->assertNotNull($run->fresh()->finished_at);
    }
}
