<?php

namespace Tests\Feature;

use App\Livewire\Automation\MetadataMappings;
use App\Livewire\Automation\Dashboard;
use App\Jobs\ProcessReleaseJob;
use App\Livewire\Automation\RunDetail;
use App\Models\AutomationRun;
use App\Models\MetadataMapping;
use App\Models\ReleaseJob;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OperationalDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_login_form_redirects_back_to_login_instead_of_showing_page_expired(): void
    {
        // Simulate a real expired CSRF token by sending a POST with the full
        // middleware stack active and an invalid _token value.  Production
        // Laravel converts TokenMismatchException to HttpException(419).
        // Our exception handler must intercept it and redirect to /login.
        $response = $this->withoutExceptionHandling(
            except: [\Illuminate\Session\TokenMismatchException::class]
        )->from('/login')->post('/login', [
            'username' => 'admin',
            'password' => 'admin',
            '_token' => 'expired-token',
        ]);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors('username');
    }

    public function test_operational_pages_require_authentication(): void
    {
        $this->get('/mappings')->assertRedirect('/login');
        $this->get('/sessions')->assertRedirect('/login');
    }

    public function test_operator_can_create_and_toggle_mapping(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        Livewire::test(MetadataMappings::class)->set('mappingType', 'genre')->set('sourceValue', 'Indie Pop')->set('targetValue', 'Pop')->call('save')->assertHasNoErrors();
        $mapping = MetadataMapping::firstOrFail();
        $this->assertTrue($mapping->is_active);
        Livewire::test(MetadataMappings::class)->call('toggle', $mapping->id);
        $this->assertFalse($mapping->fresh()->is_active);
    }

    public function test_failed_job_can_be_retried_from_its_run_only(): void
    {
        Queue::fake();
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => '42', 'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/42', 'idempotency_key' => 'soundfresh:42:soundon', 'status' => 'failed', 'error_code' => 'NETWORK_TIMEOUT']);
        Livewire::test(RunDetail::class, ['run' => $run])->call('retry', $job->id);
        $this->assertSame('queued', $job->fresh()->status->value);
        $this->assertNull($job->fresh()->error_code);
    }

    public function test_failed_job_can_be_retried_from_dashboard(): void
    {
        Queue::fake();
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);
        $run = AutomationRun::create(['status' => 'failed']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2045',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2045',
            'idempotency_key' => 'soundfresh:2045:soundon',
            'status' => 'failed',
            'error_code' => 'NETWORK_TIMEOUT',
            'error_message' => 'cURL error 28: Operation timed out',
        ]);

        app(Dashboard::class)->retryRelease($job->id);

        $this->assertSame('queued', $job->fresh()->status->value);
        $this->assertNull($job->fresh()->error_code);
        $this->assertSame('running', $run->fresh()->status->value);
        Queue::assertPushed(ProcessReleaseJob::class);
    }

    public function test_dashboard_progress_only_counts_jobs_with_a_soundon_draft(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);

        $run = AutomationRun::create([
            'status' => 'running',
            'pending_found' => 3,
            'summary_json' => ['pending_release_ids' => ['1', '2', '3']],
        ]);

        foreach ([
            ['1', 'completed', 'DRAFT-1'],
            ['2', 'failed', null],
            ['3', 'needs_attention', null],
        ] as [$releaseId, $status, $draftId]) {
            ReleaseJob::create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => $releaseId,
                'soundfresh_release_url' => "https://cms.soundfresh.id/admin/releases/{$releaseId}",
                'idempotency_key' => "soundfresh:{$releaseId}:soundon",
                'status' => $status,
                'soundon_draft_id' => $draftId,
            ]);
        }

        Livewire::test(Dashboard::class)
            ->assertSee('1 / 3 selesai (33%)');
    }

    public function test_dashboard_hides_stopped_and_status_check_runs(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);

        $visible = AutomationRun::create(['status' => 'completed']);
        $stopped = AutomationRun::create(['status' => 'stopped']);
        $statusCheck = AutomationRun::create(['status' => 'completed', 'summary_json' => ['purpose' => 'soundon_status_check']]);

        Livewire::test(Dashboard::class)
            ->assertSee($visible->id)
            ->assertDontSee($stopped->id)
            ->assertDontSee($statusCheck->id)
            ->assertSee('Tidak ada proses aktif')
            ->assertDontSee('Pause')
            ->assertDontSee('Continue');
    }

    public function test_dashboard_filters_uploaded_and_failed_releases_by_tab(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);

        $run = AutomationRun::create([
            'status' => 'completed',
            'pending_found' => 2,
            'summary_json' => ['pending_release_ids' => ['uploaded-1', 'failed-1']],
        ]);
        ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'uploaded-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/uploaded-1',
            'idempotency_key' => 'uploaded-1',
            'release_title' => 'Rilisan Berhasil',
            'status' => 'completed',
            'soundon_draft_id' => 'DRAFT-1',
        ]);
        ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'failed-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/failed-1',
            'idempotency_key' => 'failed-1',
            'release_title' => 'Rilisan Gagal',
            'status' => 'failed',
            'error_code' => 'AUDIO_UPLOAD_FAILED',
            'error_message' => 'Audio ditolak SoundOn.',
        ]);

        Livewire::test(Dashboard::class)
            ->assertSee('Selesai terupload (1)')
            ->assertSee('Gagal (1)')
            ->call('setReleaseTab', 'uploaded')
            ->assertSee('Rilisan Berhasil')
            ->assertDontSee('Rilisan Gagal')
            ->call('setReleaseTab', 'failed')
            ->assertSee('Rilisan Gagal')
            ->assertDontSee('Rilisan Berhasil');
    }

    public function test_retry_marks_release_for_fresh_soundon_duplicate_check(): void
    {
        Queue::fake();
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);
        $run = AutomationRun::create(['status' => 'failed', 'summary_json' => ['duplicate_check_completed' => true]]);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'retry-duplicate-check',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/retry-duplicate-check',
            'idempotency_key' => 'retry-duplicate-check',
            'status' => 'failed',
            'error_code' => 'AUDIO_UPLOAD_FAILED',
        ]);

        Livewire::test(Dashboard::class)->call('retryRelease', $job->id);

        $this->assertContains((string) $job->id, $run->fresh()->summary_json['fresh_duplicate_check_job_ids'] ?? []);
    }

    public function test_operator_can_stop_all_workers_from_dashboard(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);

        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '999',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/999',
            'idempotency_key' => 'soundfresh:999:soundon',
            'status' => 'running',
        ]);

        Livewire::test(Dashboard::class)
            ->call('stopAllWorkers')
            ->assertSet('showWorkerOperationModal', true)
            ->assertSet('workerOperationTitle', 'Semua worker dihentikan')
            ->assertSee('Semua worker dihentikan')
            ->assertDispatched('workers-stopped');

        $this->assertSame('stopped', $run->fresh()->status->value);
        $this->assertSame('stopped', $job->fresh()->status->value);
    }

    public function test_operator_can_control_individual_workers(): void
    {
        $this->seed(AccessControlSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('Operator');
        $this->actingAs($user);

        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '888',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/888',
            'idempotency_key' => 'soundfresh:888:soundon',
            'status' => 'running',
            'soundon_check_status' => 'checking',
        ]);

        Livewire::test(Dashboard::class)
            ->call('stopQueueWorker')
            ->assertSet('uploadMessage', 'Pipeline queue worker berhasil dihentikan.')
            ->assertSet('showWorkerOperationModal', true)
            ->assertSet('workerOperationTitle', 'Pipeline queue dihentikan');

        $this->assertSame('stopped', $run->fresh()->status->value);
        $this->assertSame('stopped', $job->fresh()->status->value);

        Livewire::test(Dashboard::class)
            ->call('stopCheckWorker')
            ->assertSet('uploadMessage', 'Verification & check worker dihentikan.')
            ->assertSet('showWorkerOperationModal', true)
            ->assertSet('workerOperationTitle', 'Verification & check worker dihentikan')
            ->call('closeWorkerOperationModal')
            ->assertSet('showWorkerOperationModal', false);

        $this->assertSame('stopped', $job->fresh()->soundon_check_status);
    }
}
