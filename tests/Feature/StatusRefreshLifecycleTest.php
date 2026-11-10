<?php

namespace Tests\Feature;

use App\Enums\AutomationRunStatus;
use App\Jobs\QueueSoundOnStatusChecks;
use App\Livewire\Automation\ReleaseStatus;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class StatusRefreshLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        foreach (['soundfresh', 'soundon'] as $platform) {
            AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active',
                'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now()]);
        }
        Queue::fake();
    }

    private function release(AutomationRun $run, string $title, array $extra = []): ReleaseJob
    {
        return ReleaseJob::create([...['automation_run_id' => $run->id, 'soundfresh_release_id' => $title,
            'soundfresh_release_url' => 'https://dimensisuara.id/admin/releases/'.$title,
            'idempotency_key' => 'soundfresh:'.$title.':soundon', 'release_title' => $title,
            'soundfresh_workflow_status' => 'uploading', 'soundon_check_status' => 'detected',
            'status' => 'completed', 'checkpoint' => 'discovered'], ...$extra]);
    }

    private function queuedWork(string $runId): void
    {
        DB::table('jobs')->insert(['queue' => 'status-checks', 'payload' => json_encode(['run' => $runId]),
            'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()]);
    }

    public function test_new_scan_resets_all_filters_and_hides_pipeline_history_without_deleting_it(): void
    {
        $history = $this->release(AutomationRun::create(['status' => 'completed']), 'Riwayat Lama');
        $page = Livewire::test(ReleaseStatus::class)->assertSee('Riwayat Lama')
            ->set('search', 'Riwayat')->set('status', 'failed')->call('filterByReleaseDate', 'today')
            ->call('refreshSoundOnStatuses')->assertSet('search', '')->assertSet('status', 'all')
            ->assertSet('soundOnStatus', 'all')->assertSet('releaseDateFilter', 'all')
            ->assertSee('Menunggu antrean pemeriksaan...')->assertSee('Tabel sebelumnya sudah dikosongkan')
            ->assertDontSee('Riwayat Lama');
        $this->assertNotNull($page->get('statusRunId'));
        $this->assertNotNull($history->fresh());
        Livewire::test(ReleaseStatus::class)->assertSet('statusRunId', $page->get('statusRunId'))->assertDontSee('Riwayat Lama');
    }

    public function test_latest_rows_and_real_progress_appear_and_completion_unlocks_despite_other_queue_jobs(): void
    {
        $page = Livewire::test(ReleaseStatus::class)->call('refreshSoundOnStatuses');
        $id = $page->get('statusRunId');
        $run = AutomationRun::findOrFail($id);
        $run->update(['status' => 'running', 'pending_found' => 2, 'summary_json' => ['purpose' => 'soundon_status_check', 'phase' => 'checking']]);
        $first = $this->release($run, 'Hasil Terbaru', ['soundon_release_status' => 'delivery']);
        $second = $this->release($run, 'Sedang Dicari', ['soundon_release_status' => 'under_review', 'soundon_check_status' => 'checking', 'soundon_check_progress' => 20]);
        $this->queuedWork($id);
        $page->call('pollStatusUpdates')->assertSee('Hasil Terbaru')->assertSee('Sedang Dicari')
            ->assertSee('Memeriksa SoundOn 1 / 2')->assertSee('Sedang diperiksa...')->assertSet('bulkCheckRequested', true);
        $second->update(['soundon_check_status' => 'detected', 'soundon_release_status' => 'live']);
        $run->update(['status' => 'completed', 'finished_at' => now()]);
        $page->call('pollStatusUpdates')->assertSet('bulkCheckRequested', false)
            ->assertSee('Pemeriksaan terbaru selesai: 2 / 2 rilisan')->assertSee('Live')->assertSee('Cek Status SoundOn');
        $this->assertSame('delivery', $first->fresh()->soundon_release_status);
    }

    public function test_orphaned_run_is_recovered_promptly_even_when_another_run_has_queue_work(): void
    {
        $page = Livewire::test(ReleaseStatus::class)->call('refreshSoundOnStatuses');
        $id = $page->get('statusRunId');
        $run = AutomationRun::findOrFail($id);
        $run->update(['status' => 'running', 'started_at' => now()->subMinute()]);
        $item = $this->release($run, 'Terputus', ['soundon_check_status' => 'checking']);
        $this->queuedWork('unrelated-status-work');
        $page->call('pollStatusUpdates')->assertSet('bulkCheckRequested', false)->assertSee('Pemeriksaan yang macet');
        $this->assertSame(AutomationRunStatus::Failed, $run->fresh()->status);
        $this->assertSame('failed', $item->fresh()->soundon_check_status);
    }

    public function test_dispatcher_failure_immediately_updates_the_button_and_error_message(): void
    {
        $page = Livewire::test(ReleaseStatus::class)->call('refreshSoundOnStatuses');
        $id = $page->get('statusRunId');
        $this->queuedWork($id);
        (new QueueSoundOnStatusChecks(null, 'uploading', $id))->failed(new \RuntimeException('Worker CMS berhenti'));
        $page->call('pollStatusUpdates')->assertSet('bulkCheckRequested', false)->assertSee('Pemeriksaan gagal: Worker CMS berhenti');
    }

    public function test_old_dispatcher_cannot_recreate_a_removed_snapshot(): void
    {
        Http::fake();
        app()->call([new QueueSoundOnStatusChecks(null, 'uploading', 'missing-old-run'), 'handle']);
        $this->assertDatabaseCount('automation_runs', 0);
        Http::assertNothingSent();
    }

    public function test_reserved_legacy_dispatcher_is_not_mistaken_for_an_orphaned_run(): void
    {
        $run = AutomationRun::create(['status' => 'running', 'started_at' => now()->subMinute(),
            'summary_json' => ['purpose' => 'soundon_status_check', 'phase' => 'collecting']]);
        $jobId = DB::table('jobs')->insertGetId(['queue' => 'status-checks', 'payload' => '{}',
            'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()]);
        $run->update(['summary_json' => [...$run->summary_json, 'dispatcher_job_id' => (string) $jobId]]);
        Livewire::test(ReleaseStatus::class)->call('pollStatusUpdates')
            ->assertSet('bulkCheckRequested', true)->assertSee('Mengambil rilisan Uploading...');
        $this->assertSame(AutomationRunStatus::Running, $run->fresh()->status);
    }
}
