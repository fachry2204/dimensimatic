<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Livewire\Automation\ReleaseStatus;
use App\Jobs\CheckSoundOnReleaseStatus;
use App\Jobs\QueueSoundOnStatusChecks;
use App\Jobs\VerifySoundfreshReleaseIdentifiers;
use App\Jobs\RejectSoundfreshRelease;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use App\Enums\AutomationRunStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

final class ReleaseStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_can_open_release_status_and_see_release(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $run = AutomationRun::query()->create([
            'status' => 'completed',
            'pending_found' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-STATUS-1',
            'soundfresh_release_url' => 'https://soundfresh.example/releases/SF-STATUS-1',
            'idempotency_key' => 'release-status-test-1',
            'release_title' => 'Rilisan Status Pengujian',
            'artist_name' => 'Artis Pengujian',
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundfresh_workflow_status' => 'under_review',
            'soundon_draft_id' => 'DRAFT-STATUS-1',
            'soundon_check_status' => 'detected',
        ]);

        $this->actingAs($viewer)
            ->get('/release-status')
            ->assertOk()
            ->assertSee('Cek Status Rilis')
            ->assertSee('Status pemeriksaan')
            ->assertDontSee('Checkpoint')
            ->assertSee('Rilisan Status Pengujian')
            ->assertSee('DRAFT-STATUS-1');
    }

    public function test_release_status_polling_stays_active_and_renders_new_rows_without_browser_refresh(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);

        $screen = Livewire::test(ReleaseStatus::class)
            ->assertSeeHtml('wire:poll.3s.keep-alive="pollStatusUpdates"')
            ->assertDontSee('Rilisan Polling Otomatis');

        $run = AutomationRun::query()->create([
            'status' => 'running',
            'summary_json' => ['purpose' => 'soundon_status_check', 'soundfresh_source' => 'uploading'],
        ]);
        ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-POLL-AUTO',
            'soundfresh_release_url' => 'https://soundfresh.example/releases/SF-POLL-AUTO',
            'idempotency_key' => 'soundfresh:SF-POLL-AUTO:soundon',
            'release_title' => 'Rilisan Polling Otomatis',
            'status' => 'completed',
            'checkpoint' => 'discovered',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_check_status' => 'checking',
        ]);

        $screen->call('pollStatusUpdates')
            ->assertSee('Rilisan Polling Otomatis');
    }

    public function test_all_collected_uploading_rows_are_displayed_without_a_row_cap(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $run = AutomationRun::query()->create(['status' => 'completed']);
        for ($i = 1; $i <= 337; $i++) {
            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => 'ALL-'.$i,
                'soundfresh_release_url' => 'https://soundfresh.example/releases/'.$i,
                'idempotency_key' => 'all-uploading-'.$i,
                'release_title' => 'Uploading '.$i,
                'status' => 'completed',
                'checkpoint' => 'discovered',
                'soundfresh_workflow_status' => 'uploading',
                'soundon_check_status' => 'detected',
            ]);
        }
        Livewire::test(ReleaseStatus::class)
            ->assertViewHas('jobs', fn ($jobs) => $jobs->count() === 337)
            ->assertViewHas('totalJobs', 337)
            ->assertDontSeeHtml('wire:model.live="checkSourceTab"');
    }

    public function test_release_status_requires_authentication(): void
    {
        $this->get('/release-status')->assertRedirect('/login');
    }

    public function test_unexamined_automation_records_are_not_mixed_into_status_results(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $run = AutomationRun::query()->create(['status' => 'completed']);
        ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-STALE-1',
            'soundfresh_release_url' => 'https://soundfresh.example/releases/stale',
            'idempotency_key' => 'soundfresh:SF-STALE-1:soundon',
            'release_title' => 'Record Automation Lama',
            'status' => 'completed',
            'checkpoint' => 'discovered',
            'soundon_check_status' => null,
        ]);

        $this->actingAs($viewer)->get('/release-status')
            ->assertOk()
            ->assertDontSee('Record Automation Lama');
    }

    public function test_delivery_status_displays_upc_and_isrc(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $run = AutomationRun::query()->create(['status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-DELIVERY-1',
            'soundfresh_release_url' => 'https://soundfresh.example/releases/SF-DELIVERY-1',
            'idempotency_key' => 'release-status-delivery-1',
            'release_title' => 'Rilisan Delivery',
            'artist_name' => 'Artis Delivery',
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_release_status' => 'delivery',
            'soundon_upc' => '1234567890123',
            'soundon_isrcs_json' => ['IDABC2612345'],
            'soundon_status_checked_at' => now(),
            'soundon_check_status' => 'detected',
        ]);

        $this->actingAs($viewer)->get('/release-status')
            ->assertOk()
            ->assertSee('Delivery')
            ->assertSee('1234567890123')
            ->assertSee('IDABC2612345');
    }

    public function test_not_approved_status_is_stored_and_filterable(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        AutomationAccount::query()->create([
            'platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
        ]);
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'SF-REJECTED-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/rejected',
            'idempotency_key' => 'status-rejected-1', 'release_title' => 'Rilisan Ditolak',
            'artist_name' => 'Artis', 'track_count' => 1, 'status' => 'completed',
            'checkpoint' => 'draft_saved', 'soundon_check_status' => 'queued',
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['matches' => [
            $job->id => ['found' => true, 'status' => 'not_approved', 'release_url' => 'https://soundon.example/rejected', 'upc' => null, 'isrcs' => [], 'reason' => 'Artwork buram'],
        ]]])]);

        app()->call([new CheckSoundOnReleaseStatus($job->id), 'handle']);

        $this->assertSame('not_approved', $job->fresh()->soundon_release_status);
        $this->assertSame('Artwork buram', $job->fresh()->soundon_rejection_reason);
        Livewire::test(ReleaseStatus::class)
            ->set('soundOnStatus', 'not_approved')
            ->assertSee('Rilisan Ditolak')
            ->assertSee('Not Approved')
            ->assertSee('Alasan: Artwork buram')
            ->assertSee('Reject di Dimensi Suara');
    }

    public function test_not_approved_release_can_be_rejected_in_soundfresh_with_the_same_reason(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        AutomationAccount::query()->create([
            'platform' => 'soundfresh', 'name' => 'Dimensimatic', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
        ]);
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-REJECT-ACTION',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/reject-action',
            'idempotency_key' => 'status-reject-action',
            'release_title' => 'Rilisan Harus Ditolak',
            'status' => 'completed', 'checkpoint' => 'draft_saved',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_release_status' => 'not_approved',
            'soundon_rejection_reason' => 'Audio tidak memenuhi ketentuan',
            'soundon_check_status' => 'detected',
        ]);
        Livewire::test(ReleaseStatus::class)
            ->call('openRejectInSoundfresh', $job->id)
            ->assertSet('rejectReason', 'Audio tidak memenuhi ketentuan')
            ->set('rejectReason', 'Pesan penolakan yang telah dikonfirmasi operator')
            ->call('rejectInSoundfresh')
            ->assertSet('syncMessage', 'Reject untuk Rilisan Harus Ditolak telah diantrikan. Worker akan mengirim alasan ke Dimensi Suara.');

        $job->refresh();
        $this->assertSame('uploading', $job->soundfresh_workflow_status);
        $this->assertSame('rejecting', $job->soundfresh_verify_status);
        Queue::assertPushed(RejectSoundfreshRelease::class, fn ($queued) => $queued->releaseJobId === $job->id && $queued->reason === 'Pesan penolakan yang telah dikonfirmasi operator');
    }

    public function test_soundon_status_variants_are_normalized(): void
    {
        AutomationAccount::query()->create([
            'platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
        ]);
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'SF-NORMALIZE-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/normalize',
            'idempotency_key' => 'status-normalize-1', 'release_title' => 'Status Dengan Spasi',
            'track_count' => 1, 'status' => 'completed', 'checkpoint' => 'draft_saved',
            'soundon_check_status' => 'queued',
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['matches' => [
            $job->id => ['found' => true, 'status' => "  Not   Approved  ", 'release_url' => 'https://soundon.example/rejected'],
        ]]])]);

        app()->call([new CheckSoundOnReleaseStatus($job->id), 'handle']);

        $job->refresh();
        $this->assertSame('not_approved', $job->soundon_release_status);
        $this->assertSame('detected', $job->soundon_check_status);
        $this->assertNull($job->soundon_check_error);
    }

    public function test_live_status_stores_upc_and_isrc_and_queues_soundfresh_verification(): void
    {
        AutomationAccount::query()->create([
            'platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
        ]);
        Queue::fake();
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-LIVE-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/live',
            'idempotency_key' => 'status-live-1',
            'release_title' => 'Rilisan Live',
            'track_count' => 1,
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_check_status' => 'queued',
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['matches' => [
            $job->id => [
                'found' => true, 'status' => 'live', 'release_url' => 'https://soundon.example/live',
                'upc' => '5063962826298', 'isrcs' => ['QT8CF2691505'], 'reason' => null,
            ],
        ]]])]);

        app()->call([new CheckSoundOnReleaseStatus($job->id), 'handle']);

        $job->refresh();
        $this->assertSame('live', $job->soundon_release_status);
        $this->assertSame('5063962826298', $job->soundon_upc);
        $this->assertSame(['QT8CF2691505'], $job->soundon_isrcs_json);
        Queue::assertPushed(VerifySoundfreshReleaseIdentifiers::class, fn ($queued) => $queued->releaseJobId === $job->id);
    }

    public function test_release_date_and_day_distance_are_displayed_for_soundfresh_and_soundon(): void
    {
        CarbonImmutable::setTestNow('2026-09-03 12:00:00');
        try {
            $this->seed(AccessControlSeeder::class);
            $viewer = User::factory()->create();
            $viewer->assignRole('Viewer');
            $this->actingAs($viewer);
            $run = AutomationRun::query()->create(['status' => 'completed']);

            foreach ([
                ['Rilis Masa Lalu', '2026-08-29'],
                ['Rilis Masa Depan', '2026-09-10'],
                ['Rilis Hari Ini', '2026-09-03'],
            ] as $index => [$title, $releaseDate]) {
                ReleaseJob::query()->create([
                    'automation_run_id' => $run->id,
                    'soundfresh_release_id' => 'SF-DATE-'.$index,
                    'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/date-'.$index,
                    'idempotency_key' => 'status-date-'.$index,
                    'release_title' => $title,
                    'status' => 'completed', 'checkpoint' => 'draft_saved',
                    'soundfresh_workflow_status' => 'under_review',
                    'soundon_release_status' => 'under_review',
                    'soundon_check_status' => 'detected',
                    'metadata_snapshot_json' => ['release_date' => $releaseDate],
                ]);
            }

            Livewire::test(ReleaseStatus::class)
                ->assertSee('Tanggal rilis: 29 Aug 2026')
                ->assertSee('Rilis Terlewat · 5 hari')
                ->assertSee('Tanggal rilis: 10 Sep 2026')
                ->assertSee('7 hari lagi menuju tanggal rilis')
                ->assertSee('Rilis hari ini');
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_soundon_status_cards_filter_the_release_table(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $run = AutomationRun::query()->create(['status' => 'completed']);

        foreach ([
            ['under_review', 'Kartu Under Review'],
            ['delivery', 'Kartu Delivered'],
            ['approved', 'Kartu Approved'],
            ['not_approved', 'Kartu Not Approved'],
        ] as $index => [$status, $title]) {
            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => 'SF-CARD-'.$index,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/card-'.$index,
                'idempotency_key' => 'status-card-'.$index,
                'release_title' => $title,
                'status' => 'completed',
                'checkpoint' => 'draft_saved',
                'soundfresh_workflow_status' => 'under_review',
                'soundon_release_status' => $status,
                'soundon_check_status' => 'detected',
            ]);
        }

        Livewire::test(ReleaseStatus::class)
            ->assertSee('Kartu Under Review')
            ->assertSee('Kartu Delivered')
            ->call('filterBySoundOnStatus', 'approved')
            ->assertSet('soundOnStatus', 'approved')
            ->assertSee('Kartu Approved')
            ->assertDontSee('Kartu Under Review')
            ->assertDontSee('Kartu Delivered')
            ->assertDontSee('Kartu Not Approved')
            ->assertSeeHtml('aria-pressed="true"');
    }

    public function test_all_releases_card_clears_the_soundon_status_filter(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $run = AutomationRun::query()->create(['status' => 'completed']);

        foreach ([['under_review', 'Rilis Review'], ['live', 'Rilis Live']] as $index => [$status, $title]) {
            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => 'SF-ALL-CARD-'.$index,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/all-card-'.$index,
                'idempotency_key' => 'status-all-card-'.$index,
                'release_title' => $title,
                'status' => 'completed',
                'checkpoint' => 'draft_saved',
                'soundfresh_workflow_status' => 'under_review',
                'soundon_release_status' => $status,
                'soundon_check_status' => 'detected',
            ]);
        }

        Livewire::test(ReleaseStatus::class)
            ->assertSee('Seluruh rilis diambil')
            ->assertSee('Rilis Review')
            ->assertSee('Rilis Live')
            ->set('soundOnStatus', 'live')
            ->assertDontSee('Rilis Review')
            ->call('filterBySoundOnStatus', 'all')
            ->assertSet('soundOnStatus', 'all')
            ->assertSee('Rilis Review')
            ->assertSee('Rilis Live');
    }

    public function test_status_baru_diambil_shows_only_latest_scan_and_excludes_saved_review_and_rejection(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);

        $oldRun = AutomationRun::query()->create([
            'status' => 'completed',
            'summary_json' => ['purpose' => 'soundon_status_check'],
            'updated_at' => now()->subMinute(),
        ]);
        $latestRun = AutomationRun::query()->create([
            'status' => 'completed',
            'summary_json' => ['purpose' => 'soundon_status_check'],
            'updated_at' => now(),
        ]);
        foreach ([
            [$oldRun, 'SF-OLD-SNAPSHOT', 'Rilisan Scan Lama', null],
            [$latestRun, 'SF-NEW-SNAPSHOT', 'Rilisan Scan Terbaru', null],
            [$latestRun, 'SF-REVIEW-SNAPSHOT', 'Rilisan Sudah Review', 'under_review'],
            [$latestRun, 'SF-REJECT-SNAPSHOT', 'Rilisan Sudah Reject', 'not_approved'],
        ] as [$run, $releaseId, $title, $soundOnStatus]) {
            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => $releaseId,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/'.$releaseId,
                'idempotency_key' => 'status-snapshot-'.$releaseId,
                'release_title' => $title,
                'status' => 'completed', 'checkpoint' => 'discovered',
                'soundfresh_workflow_status' => 'uploading',
                'soundon_release_status' => $soundOnStatus,
                'soundon_check_status' => 'detected',
            ]);
        }

        Livewire::test(ReleaseStatus::class)
            ->assertSet('soundOnStatus', 'new')
            ->assertSee('Status Baru Diambil')
            ->assertSee('Rilisan Scan Terbaru')
            ->assertDontSee('Rilisan Scan Lama')
            ->assertDontSee('Rilisan Sudah Review')
            ->assertDontSee('Rilisan Sudah Reject')
            ->call('filterBySoundOnStatus', 'under_review')
            ->assertSee('Rilisan Sudah Review');
    }

    public function test_latest_status_results_appear_while_bulk_check_is_still_running(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $run = AutomationRun::query()->create([
            'status' => 'running',
            'summary_json' => ['purpose' => 'soundon_status_check'],
        ]);
        ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-LIVE-RESULT',
            'soundfresh_release_url' => 'https://soundfresh.example/releases/live-result',
            'idempotency_key' => 'status-live-result',
            'release_title' => 'Hasil Langsung Terlihat',
            'status' => 'completed',
            'checkpoint' => 'discovered',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_check_status' => 'detected',
            'soundon_release_status' => 'delivery',
        ]);

        Livewire::test(ReleaseStatus::class)
            ->set('bulkCheckRequested', true)
            ->assertSee('Hasil Langsung Terlihat');
    }

    public function test_starting_full_status_refresh_clears_the_previous_new_status_snapshot(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        foreach (['soundfresh' => 'Dimensimatic', 'soundon' => 'SoundOn'] as $platform => $name) {
            AutomationAccount::query()->create([
                'platform' => $platform, 'name' => $name, 'status' => 'active',
                'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
            ]);
        }
        $oldRun = AutomationRun::query()->create([
            'status' => 'completed', 'summary_json' => ['purpose' => 'soundon_status_check'],
        ]);
        ReleaseJob::query()->create([
            'automation_run_id' => $oldRun->id,
            'soundfresh_release_id' => 'SF-OLD-CLEAR',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/old-clear',
            'idempotency_key' => 'status-old-clear', 'release_title' => 'Tabel Lama',
            'status' => 'completed', 'checkpoint' => 'discovered',
            'soundfresh_workflow_status' => 'uploading', 'soundon_check_status' => 'detected',
        ]);

        Livewire::test(ReleaseStatus::class)
            ->assertSee('Tabel Lama')
            ->call('refreshSoundOnStatuses')
            ->assertSet('bulkCheckRequested', true)
            ->assertDontSee('Tabel Lama');

        Queue::assertPushed(QueueSoundOnStatusChecks::class, fn ($job): bool => filled($job->statusRunId));
    }

    public function test_today_and_overdue_review_cards_only_count_and_filter_under_review_releases(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $today = now(config('automation.release_timezone', 'Asia/Bangkok'))->toDateString();
        $overdue = now(config('automation.release_timezone', 'Asia/Bangkok'))->subDays(2)->toDateString();

        foreach ([
            ['Hari Ini Review', 'under_review', $today],
            ['Terlewat Review', 'under_review', $overdue],
            ['Terlewat Delivered', 'delivery', $overdue],
        ] as $index => [$title, $soundOnStatus, $releaseDate]) {
            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => 'SF-DATE-CARD-'.$index,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/date-card-'.$index,
                'idempotency_key' => 'status-date-card-'.$index,
                'release_title' => $title,
                'status' => 'completed',
                'checkpoint' => 'draft_saved',
                'soundfresh_workflow_status' => 'under_review',
                'soundon_release_status' => $soundOnStatus,
                'soundon_check_status' => 'detected',
                'metadata_snapshot_json' => ['release_date' => $releaseDate],
            ]);
        }

        Livewire::test(ReleaseStatus::class)
            ->assertSee('Rilis Hari Ini · Under Review')
            ->assertSee('Rilis Terlewat · Under Review')
            ->call('filterByReleaseDate', 'today')
            ->assertSet('releaseDateFilter', 'today')
            ->assertSet('soundOnStatus', 'under_review')
            ->assertSee('Hari Ini Review')
            ->assertDontSee('Terlewat Review')
            ->assertDontSee('Terlewat Delivered')
            ->call('filterByReleaseDate', 'overdue')
            ->assertSet('releaseDateFilter', 'overdue')
            ->assertSee('Terlewat Review')
            ->assertDontSee('Hari Ini Review')
            ->assertDontSee('Terlewat Delivered');
    }

    public function test_status_refresh_uses_soundfresh_uploading_releases_only(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);

        AutomationAccount::query()->create([
            'platform' => 'soundfresh',
            'name' => 'Dimensimatic',
            'status' => 'active',
            'session_state_encrypted' => ['cookies' => []],
            'last_authenticated_at' => now(),
        ]);
        AutomationAccount::query()->create([
            'platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
        ]);
        Queue::fake();

        Http::fake(function ($request) {
            $this->assertTrue(str_ends_with($request->url(), '/v1/soundfresh/pending'));
            $status = $request['options']['release_status'] ?? null;
            $this->assertSame('uploading', $status);

            return Http::response(['success' => true, 'data' => ['items' => [[
                'release_id' => 'SF-UPLOADING-1',
                'title' => 'Rilisan Uploading',
                'primary_artist' => '',
                'detail_url' => 'https://soundfresh.example/releases/'.$status,
                'track_count' => 2,
                'workflow_status' => $status,
                'release_date' => 'September 9, 2026',
            ]]]]);
        });

        $existing = ReleaseJob::query()->create([
            'automation_run_id' => AutomationRun::query()->create(['status' => 'completed'])->id,
            'soundfresh_release_id' => 'SF-EXISTING-STATUS',
            'soundfresh_release_url' => 'https://soundfresh.example/releases/existing',
            'idempotency_key' => 'soundfresh:SF-EXISTING-STATUS:soundon',
            'release_title' => 'Rilisan Lama', 'track_count' => 1,
            'status' => 'completed', 'checkpoint' => 'discovered',
            'soundon_check_status' => 'detected', 'soundon_check_progress' => 100,
        ]);

        Livewire::test(ReleaseStatus::class)
            ->call('refreshSoundOnStatuses')
            ->assertSet('bulkCheckRequested', true)
            ->assertSet('syncMessage', 'Sedang mengambil seluruh rilisan Uploading Dimensi Suara dan memeriksa ulang status SoundOn.');

        // Non-status pipeline data must remain intact when replacing only the status snapshot.
        $this->assertDatabaseHas('release_jobs', ['id' => $existing->id, 'soundon_check_status' => 'detected']);

        Queue::assertPushed(QueueSoundOnStatusChecks::class);
        Queue::assertPushed(QueueSoundOnStatusChecks::class, fn ($queued) => $queued->queue === 'status-checks' && $queued->sourceTab === 'uploading');
        app()->call([new QueueSoundOnStatusChecks($viewer->id, 'both'), 'handle']);

        $uploadingJob = ReleaseJob::query()->where('soundfresh_release_id', 'SF-UPLOADING-1')->sole();
        $this->assertSame(5, $uploadingJob->soundon_check_progress);
        $this->assertSame('September 9, 2026', data_get($uploadingJob->metadata_snapshot_json, 'release_date'));
        $statusRun = AutomationRun::query()->findOrFail($uploadingJob->automation_run_id);
        Queue::assertPushed(CheckSoundOnReleaseStatus::class, fn ($queued) => in_array($uploadingJob->id, $queued->releaseJobIds, true) && $queued->sourceStatus === 'uploading');
        Queue::assertPushed(CheckSoundOnReleaseStatus::class, fn ($queued) => $queued->queue === 'status-checks');
        $this->assertSame('running', $statusRun->status->value);
    }

    public function test_status_check_does_not_stop_a_pending_collection_or_its_pipeline_queue(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        AutomationAccount::query()->create([
            'platform' => 'soundfresh',
            'name' => 'Dimensimatic',
            'status' => 'active',
            'session_state_encrypted' => ['cookies' => []],
            'last_authenticated_at' => now(),
        ]);
        $pendingRun = AutomationRun::query()->create([
            'status' => 'queued',
            'summary_json' => ['collection_pending' => true],
        ]);
        $pendingJob = ReleaseJob::query()->create([
            'automation_run_id' => $pendingRun->id,
            'soundfresh_release_id' => 'SF-PENDING-ISOLATED',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/pending-isolated',
            'idempotency_key' => 'status-isolation-pending',
            'release_title' => 'Tetap Dikumpulkan',
            'status' => 'queued',
            'checkpoint' => 'discovered',
        ]);
        DB::table('jobs')->insert([
            'queue' => 'release-automation',
            'payload' => '{"displayName":"App\\Jobs\\CollectPendingSoundfreshReleases"}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        Http::fake(['*' => Http::response(['success' => true, 'data' => ['items' => []]])]);

        app()->call([new QueueSoundOnStatusChecks($viewer->id), 'handle']);

        $this->assertSame('queued', $pendingRun->fresh()->status->value);
        $this->assertSame('queued', $pendingJob->fresh()->status->value);
        $this->assertDatabaseHas('jobs', ['queue' => 'release-automation']);
    }

    public function test_status_page_removes_the_soundfresh_source_selector(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        foreach (['soundfresh' => 'Dimensimatic', 'soundon' => 'SoundOn'] as $platform => $name) {
            AutomationAccount::query()->create([
                'platform' => $platform, 'name' => $name, 'status' => 'active',
                'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
            ]);
        }

        Livewire::test(ReleaseStatus::class)
            ->assertDontSee('Tampilkan data Dimensi Suara')
            ->assertDontSeeHtml('wire:model.live="checkSourceTab"')
            ->assertDontSeeHtml('aria-label="Tab status SoundOn"')
            ->call('refreshSoundOnStatuses')
            ->assertSet('syncMessage', 'Sedang mengambil seluruh rilisan Uploading Dimensi Suara dan memeriksa ulang status SoundOn.');

        Queue::assertPushed(QueueSoundOnStatusChecks::class, fn ($queued) => $queued->sourceTab === 'uploading');
    }

    public function test_starting_bulk_check_clears_previous_status_check_data_before_queueing_fresh_uploading_scan(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        foreach (['soundfresh' => 'Dimensimatic', 'soundon' => 'SoundOn'] as $platform => $name) {
            AutomationAccount::query()->create([
                'platform' => $platform,
                'name' => $name,
                'status' => 'active',
                'session_state_encrypted' => ['cookies' => []],
                'last_authenticated_at' => now(),
            ]);
        }
        Queue::fake();
        $oldRun = AutomationRun::query()->create([
            'triggered_by' => $admin->id,
            'status' => 'completed',
            'summary_json' => ['purpose' => 'soundon_status_check'],
        ]);
        ReleaseJob::query()->create([
            'automation_run_id' => $oldRun->id,
            'soundfresh_release_id' => 'OLD-STATUS-1',
            'soundfresh_release_url' => 'https://soundfresh.example/releases/old',
            'idempotency_key' => 'soundfresh:OLD-STATUS-1:soundon',
            'release_title' => 'Data Lama',
            'status' => 'completed',
            'checkpoint' => 'discovered',
            'soundfresh_workflow_status' => 'under_review',
            'soundon_check_status' => 'detected',
            'soundon_release_status' => 'under_review',
        ]);

        Livewire::test(ReleaseStatus::class)->call('refreshSoundOnStatuses');

        $this->assertDatabaseMissing('release_jobs', ['soundfresh_release_id' => 'OLD-STATUS-1']);
        $this->assertDatabaseMissing('automation_runs', ['id' => $oldRun->id]);
        Queue::assertPushed(QueueSoundOnStatusChecks::class, fn ($job) => $job->sourceTab === 'uploading');
    }

    public function test_status_page_does_not_render_the_redundant_status_tabs(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);

        Livewire::test(ReleaseStatus::class)
            ->assertDontSeeHtml('aria-label="Tab status SoundOn"')
            ->assertDontSee('Cek ulang status tab ini');
    }

    public function test_bulk_status_check_rechecks_all_uploading_releases_including_saved_statuses(): void
    {
        AutomationAccount::query()->create([
            'platform' => 'soundfresh',
            'name' => 'Dimensimatic',
            'status' => 'active',
            'session_state_encrypted' => ['cookies' => []],
            'last_authenticated_at' => now(),
        ]);
        Queue::fake();
        $run = AutomationRun::query()->create(['status' => 'completed']);
        foreach ([['SF-SAVED-REVIEW', 'under_review'], ['SF-SAVED-REJECTED', 'not_approved']] as [$releaseId, $status]) {
            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => $releaseId,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/'.$releaseId,
                'idempotency_key' => 'soundfresh:'.$releaseId.':soundon',
                'release_title' => $releaseId,
                'status' => 'completed',
                'checkpoint' => 'discovered',
                'soundfresh_workflow_status' => 'uploading',
                'soundon_release_status' => $status,
                'soundon_check_status' => 'detected',
                'soundon_check_progress' => 100,
                'soundon_status_checked_at' => null,
            ]);
        }
        Http::fake(function ($request) {
            $this->assertSame('uploading', $request['options']['release_status'] ?? null);
            $this->assertArrayHasKey('max_items', $request['options']);
            $this->assertNull($request['options']['max_items']);

            return Http::response(['success' => true, 'data' => ['items' => [
                ['release_id' => 'SF-SAVED-REVIEW', 'title' => 'Review Lama', 'primary_artist' => '', 'detail_url' => 'https://cms.soundfresh.id/releases/SF-SAVED-REVIEW', 'track_count' => 1, 'workflow_status' => 'uploading'],
                ['release_id' => 'SF-SAVED-REJECTED', 'title' => 'Reject Lama', 'primary_artist' => '', 'detail_url' => 'https://cms.soundfresh.id/releases/SF-SAVED-REJECTED', 'track_count' => 1, 'workflow_status' => 'uploading'],
                ['release_id' => 'SF-NEW-UPLOADING', 'title' => 'Upload Baru', 'primary_artist' => '', 'detail_url' => 'https://cms.soundfresh.id/releases/SF-NEW-UPLOADING', 'track_count' => 1, 'workflow_status' => 'uploading'],
            ]]]);
        });

        app()->call([new QueueSoundOnStatusChecks(null, 'uploading'), 'handle']);

        Queue::assertPushed(CheckSoundOnReleaseStatus::class, function ($queued): bool {
            $releaseIds = ReleaseJob::query()
                ->whereIn('id', $queued->releaseJobIds)
                ->pluck('soundfresh_release_id')
                ->sort()
                ->values()
                ->all();

            return $releaseIds === ['SF-NEW-UPLOADING', 'SF-SAVED-REJECTED', 'SF-SAVED-REVIEW'];
        });
        $this->assertSame('queued', ReleaseJob::query()->where('soundfresh_release_id', 'SF-SAVED-REVIEW')->sole()->soundon_check_status);
        $this->assertSame('queued', ReleaseJob::query()->where('soundfresh_release_id', 'SF-SAVED-REJECTED')->sole()->soundon_check_status);
    }

    public function test_uploading_selection_does_not_read_under_review_tab(): void
    {
        AutomationAccount::query()->create([
            'platform' => 'soundfresh',
            'name' => 'Dimensimatic',
            'status' => 'active',
            'session_state_encrypted' => ['cookies' => []],
            'last_authenticated_at' => now(),
        ]);
        Queue::fake();
        Http::fake(function ($request) {
            $this->assertSame('uploading', $request['options']['release_status'] ?? null);

            return Http::response(['success' => true, 'data' => ['items' => []]]);
        });

        app()->call([new QueueSoundOnStatusChecks(null, 'both'), 'handle']);

        Http::assertSentCount(1);
        Queue::assertNotPushed(CheckSoundOnReleaseStatus::class);
    }

    public function test_release_table_displays_only_the_selected_soundfresh_tab(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $run = AutomationRun::query()->create(['status' => 'completed']);

        foreach ([
            ['under_review', 'Hanya Under Review'],
            ['uploading', 'Hanya Uploading'],
            ['verified', 'Jangan Tampilkan Verified'],
        ] as $index => [$workflowStatus, $title]) {
            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => 'SF-TAB-FILTER-'.$index,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/tab-filter-'.$index,
                'idempotency_key' => 'status-tab-filter-'.$index,
                'release_title' => $title,
                'status' => 'completed',
                'checkpoint' => 'discovered',
                'soundfresh_workflow_status' => $workflowStatus,
                'soundon_check_status' => 'detected',
            ]);
        }

        Livewire::test(ReleaseStatus::class)
            ->set('checkSourceTab', 'uploading')
            ->assertSee('Hanya Uploading')
            ->assertDontSee('Hanya Under Review')
            ->assertDontSee('Jangan Tampilkan Verified')
            ->set('checkSourceTab', 'under_review')
            ->assertSee('Hanya Under Review')
            ->assertDontSee('Hanya Uploading')
            ->assertDontSee('Jangan Tampilkan Verified')
            ->set('checkSourceTab', 'both')
            ->assertSee('Hanya Under Review')
            ->assertSee('Hanya Uploading')
            ->assertDontSee('Jangan Tampilkan Verified');
    }

    public function test_bulk_status_check_replaces_saved_statuses_and_rechecks_all_current_uploads(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        foreach (['soundfresh' => 'Dimensimatic', 'soundon' => 'SoundOn'] as $platform => $name) {
            AutomationAccount::query()->create([
                'platform' => $platform, 'name' => $name, 'status' => 'active',
                'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
            ]);
        }

        $run = AutomationRun::query()->create(['status' => 'completed']);
        $checked = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-CHECKED-STATUS',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/checked',
            'idempotency_key' => 'soundfresh:SF-CHECKED-STATUS:soundon',
            'release_title' => 'Status Sudah Tersimpan',
            'status' => 'completed', 'checkpoint' => 'discovered',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_release_status' => 'not_approved',
            'soundon_check_status' => 'detected',
            'soundon_check_progress' => 100,
            'soundon_status_checked_at' => now(),
        ]);
        Http::fake(fn ($request) => Http::response([
            'success' => true,
            'data' => ['items' => [[
                'release_id' => 'SF-CHECKED-STATUS',
                'title' => 'Status Sudah Tersimpan',
                'primary_artist' => '',
                'detail_url' => 'https://cms.soundfresh.id/releases/checked',
                'track_count' => 1,
                'workflow_status' => 'uploading',
            ], [
                'release_id' => 'SF-NEW-STATUS',
                'title' => 'Status Baru Dicek',
                'primary_artist' => '',
                'detail_url' => 'https://cms.soundfresh.id/releases/new',
                'track_count' => 1,
                'workflow_status' => 'uploading',
            ]]],
        ]));

        Livewire::test(ReleaseStatus::class)
            ->set('checkSourceTab', 'uploading')
            ->call('refreshSoundOnStatuses');
        app()->call([new QueueSoundOnStatusChecks($viewer->id, 'uploading'), 'handle']);

        $checked->refresh();
        $this->assertSame('not_approved', $checked->soundon_release_status);
        $this->assertSame('queued', $checked->soundon_check_status);
        $new = ReleaseJob::query()->where('soundfresh_release_id', 'SF-NEW-STATUS')->sole();
        $this->assertSame('queued', $new->soundon_check_status);
        Queue::assertPushed(CheckSoundOnReleaseStatus::class, function ($queued) use ($checked, $new): bool {
            $ids = $queued->releaseJobIds;
            sort($ids);
            $expected = [$checked->id, $new->id];
            sort($expected);
            return $ids === $expected;
        });
    }

    public function test_installer_kills_and_removes_old_worker_files_before_extracting_update(): void
    {
        $source = file_get_contents(base_path('scripts/installer/Program.cs'));
        $this->assertStringContainsString('StopOwnedRuntimeProcesses(target)', $source);
        $this->assertStringContainsString('DeleteOldWorkerFiles(target)', $source);
        $this->assertStringContainsString('automation-worker', $source);
        $this->assertStringContainsString('automation-worker.pid', $source);
    }

    public function test_single_release_can_be_queued_for_status_check_from_its_row(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'SF-SINGLE-CHECK',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/single',
            'idempotency_key' => 'soundfresh:SF-SINGLE-CHECK:soundon',
            'release_title' => 'Rilisan Cek Tunggal', 'track_count' => 1,
            'status' => 'completed', 'checkpoint' => 'discovered',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_check_status' => 'detected', 'soundon_check_progress' => 100,
        ]);

        Livewire::test(ReleaseStatus::class)
            ->assertSeeHtml("checkSoundOnStatus('{$job->id}')")
            ->call('checkSoundOnStatus', $job->id)
            ->assertSee('Menunggu')
            ->assertSet('syncMessage', 'Pemeriksaan SoundOn untuk Rilisan Cek Tunggal sudah masuk antrean.');

        $job->refresh();
        $this->assertSame('queued', $job->soundon_check_status);
        $this->assertSame(5, $job->soundon_check_progress);
        Queue::assertPushed(CheckSoundOnReleaseStatus::class, fn ($queued) => $queued->releaseJobIds === [$job->id]);
    }

    public function test_queued_status_check_updates_release_and_progress(): void
    {
        AutomationAccount::query()->create([
            'platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'last_authenticated_at' => now(),
        ]);
        $run = AutomationRun::query()->create(['status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'SF-CHECK-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/1', 'idempotency_key' => 'status-check-job-1',
            'release_title' => 'Judul Tanpa Artis', 'artist_name' => null, 'track_count' => 2,
            'status' => 'completed', 'checkpoint' => 'draft_saved', 'soundon_check_status' => 'queued',
        ]);
        Http::fake(function ($request) use ($job) {
            $this->assertSame('', $request['items'][0]['artist']);

            return Http::response(['success' => true, 'data' => ['matches' => [
                $job->id => ['found' => true, 'status' => 'delivery', 'release_url' => 'https://soundon.example/1',
                    'upc' => '1234567890123', 'isrcs' => ['IDABC2612345']],
            ]]]);
        });

        app()->call([new CheckSoundOnReleaseStatus($job->id), 'handle']);

        $job->refresh();
        $this->assertSame('detected', $job->soundon_check_status);
        $this->assertSame(100, $job->soundon_check_progress);
        $this->assertSame('delivery', $job->soundon_release_status);
        $this->assertSame('1234567890123', $job->soundon_upc);
    }

    public function test_checking_release_displays_per_release_progress(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $run = AutomationRun::query()->create(['status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        ReleaseJob::query()->create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'SF-PROGRESS-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/1', 'idempotency_key' => 'status-progress-1',
            'release_title' => 'Rilisan Sedang Dicek', 'track_count' => 1, 'status' => 'completed',
            'checkpoint' => 'draft_saved', 'soundfresh_workflow_status' => 'under_review',
            'soundon_check_status' => 'checking', 'soundon_check_progress' => 20,
        ]);

        $this->actingAs($viewer)->get('/release-status')
            ->assertOk()
            ->assertSee('Sedang diperiksa')
            ->assertSee('Progress pemeriksaan Rilisan Sedang Dicek')
            ->assertSee('Mencari Rilisan Sedang Dicek di SoundOn');
    }

    public function test_poll_finalizes_orphaned_checking_releases_when_queue_is_empty(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $run = AutomationRun::query()->create(['status' => 'running', 'started_at' => now()]);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-ORPHAN-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/orphan',
            'idempotency_key' => 'status-orphan-1',
            'release_title' => 'Pemeriksaan Terputus',
            'track_count' => 1,
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundon_check_status' => 'checking',
            'soundon_check_progress' => 20,
        ]);

        Livewire::test(ReleaseStatus::class)
            ->set('bulkCheckRequested', true)
            ->call('pollStatusUpdates')
            ->assertSet('bulkCheckRequested', false)
            ->assertSet('syncMessage', 'Pemeriksaan telah berhenti. Semua status loading sudah difinalisasi; rilisan yang terputus dapat diperiksa ulang.');

        $job->refresh();
        $this->assertSame('failed', $job->soundon_check_status);
        $this->assertSame(100, $job->soundon_check_progress);
        $this->assertNotNull($job->soundon_check_finished_at);
    }

    public function test_poll_recovers_an_abandoned_status_run_and_unlocks_the_scan(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $staleAt = now()->subHours(2);
        $run = AutomationRun::query()->create([
            'status' => 'running',
            'started_at' => $staleAt,
            'created_at' => $staleAt,
            'updated_at' => $staleAt,
            'summary_json' => ['purpose' => 'soundon_status_check', 'soundfresh_source' => 'uploading'],
        ]);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-STALE-STATUS-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/stale-status',
            'idempotency_key' => 'stale-status-check-1',
            'release_title' => 'Pemeriksaan Lama',
            'track_count' => 1,
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundon_check_status' => 'checking',
            'soundon_check_progress' => 20,
        ]);

        Livewire::test(ReleaseStatus::class)
            ->set('bulkCheckRequested', true)
            ->call('pollStatusUpdates')
            ->assertSet('bulkCheckRequested', false)
            ->assertSet('syncMessage', 'Pemeriksaan yang macet telah dihentikan dengan aman. Status rilisan yang belum selesai ditandai gagal dan dapat diperiksa ulang.');

        $this->assertSame(AutomationRunStatus::Failed, $run->fresh()->status);
        $this->assertNotNull($run->fresh()->finished_at);
        $this->assertSame('failed', $job->fresh()->soundon_check_status);
        $this->assertNotNull($job->fresh()->soundon_check_finished_at);
    }

    public function test_complete_identifiers_can_be_sent_to_soundfresh(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        $run = AutomationRun::query()->create(['status' => 'completed', 'started_at' => now(), 'finished_at' => now()]);
        $job = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-VERIFY-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/123',
            'idempotency_key' => 'release-status-verify-1',
            'release_title' => 'Rilisan Siap Verifikasi',
            'soundfresh_workflow_status' => 'uploading',
            'track_count' => 2,
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundon_release_status' => 'delivery',
            'soundon_upc' => '1234567890123',
            'soundon_isrcs_json' => ['IDABC2612345', 'IDABC2612346'],
            'soundon_check_status' => 'detected',
        ]);

        Livewire::test(ReleaseStatus::class)
            ->assertSeeHtml("sendToSoundfresh('{$job->id}')")
            ->call('sendToSoundfresh', $job->id)
            ->assertSet('syncMessage', 'Pengiriman UPC dan ISRC untuk Rilisan Siap Verifikasi telah diantrikan. Status akan diperbarui otomatis.')
            ->assertSet('verificationJobIds', [$job->id]);

        $job->refresh();
        $this->assertSame('sending', $job->soundfresh_verify_status);
        $this->assertNull($job->soundfresh_verify_error);
        Queue::assertPushed(VerifySoundfreshReleaseIdentifiers::class, fn ($queued) => $queued->releaseJobId === $job->id);
    }

    public function test_selected_uploading_releases_can_be_queued_for_bulk_verify(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $ready = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-BULK-READY',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/ready',
            'idempotency_key' => 'release-status-bulk-ready',
            'release_title' => 'Rilisan Bulk Siap',
            'track_count' => 1,
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_release_status' => 'delivery',
            'soundon_upc' => '1234567890123',
            'soundon_isrcs_json' => ['IDABC2612345'],
            'soundon_check_status' => 'detected',
        ]);

        Livewire::test(ReleaseStatus::class)
            ->set('selectedJobIds', [$ready->id])
            ->call('verifySelected')
            ->assertSet('selectedJobIds', [])
            ->assertSet('syncMessage', '1 rilisan diantrikan untuk Verify Release dan pengisian UPC/ISRC di Dimensi Suara.');

        $this->assertSame('sending', $ready->fresh()->soundfresh_verify_status);
        Queue::assertPushed(VerifySoundfreshReleaseIdentifiers::class, fn ($job) => $job->releaseJobId === $ready->id);
    }

    public function test_sending_release_cannot_be_queued_twice_for_soundfresh_verify(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        Queue::fake();
        $run = AutomationRun::query()->create(['status' => 'completed']);
        $sending = ReleaseJob::query()->create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'SF-SENDING-LOCK',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/sending-lock',
            'idempotency_key' => 'release-status-sending-lock',
            'release_title' => 'Rilisan Sedang Dikirim',
            'track_count' => 1,
            'status' => 'completed',
            'checkpoint' => 'draft_saved',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_release_status' => 'delivery',
            'soundon_upc' => '1234567890123',
            'soundon_isrcs_json' => ['IDABC2612345'],
            'soundon_check_status' => 'detected',
            'soundfresh_verify_status' => 'sending',
        ]);

        Livewire::test(ReleaseStatus::class)
            ->assertSeeHtml("value=\"{$sending->id}\" disabled")
            ->call('sendToSoundfresh', $sending->id)
            ->assertSet('syncMessage', 'Pengiriman untuk Rilisan Sedang Dikirim masih berada dalam antrean. Tunggu hasilnya sebelum mencoba kembali.');

        Queue::assertNothingPushed();
    }

    public function test_send_button_is_disabled_until_upc_and_all_isrcs_are_available(): void
    {
        $this->seed(AccessControlSeeder::class);
        $viewer = User::factory()->create();
        $viewer->assignRole('Viewer');
        $run = AutomationRun::query()->create(['status' => 'completed']);
        ReleaseJob::query()->create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'SF-INCOMPLETE-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/incomplete',
            'idempotency_key' => 'status-incomplete-1', 'release_title' => 'Identifier Belum Lengkap',
            'track_count' => 2, 'status' => 'completed', 'checkpoint' => 'draft_saved',
            'soundfresh_workflow_status' => 'uploading',
            'soundon_release_status' => 'delivery', 'soundon_upc' => '1234567890123',
            'soundon_isrcs_json' => ['IDABC2612345'], 'soundon_check_status' => 'detected',
        ]);

        $this->actingAs($viewer)->get('/release-status')
            ->assertOk()
            ->assertSee('Identifier Belum Lengkap')
            ->assertSeeHtml('aria-disabled="true"')
            ->assertSee('Tombol aktif untuk rilisan Uploading setelah UPC dan seluruh ISRC ditemukan');
    }

    public function test_under_review_report_downloads_today_and_overdue_releases_as_excel(): void
    {
        CarbonImmutable::setTestNow('2026-09-27 12:00:00');
        try {
            $this->seed(AccessControlSeeder::class);
            $viewer = User::factory()->create();
            $viewer->assignRole('Viewer');
            $this->actingAs($viewer);
            $run = AutomationRun::query()->create(['status' => 'completed']);

            foreach ([
                ['Rilis Hari Ini Excel', 'Artis Hari Ini', 'under_review', '2026-09-27', 'https://soundon.example/today'],
                ['Rilis Terlewat Excel', 'Artis Terlewat', 'under_review', '2026-09-25', 'https://soundon.example/overdue'],
                ['Rilis Mendatang', 'Artis Mendatang', 'under_review', '2026-09-28', 'https://soundon.example/future'],
                ['Rilis Sudah Live', 'Artis Live', 'live', '2026-09-25', 'https://soundon.example/live'],
            ] as $index => [$title, $artist, $soundOnStatus, $releaseDate, $soundOnUrl]) {
                ReleaseJob::query()->create([
                    'automation_run_id' => $run->id,
                    'soundfresh_release_id' => 'SF-EXCEL-'.$index,
                    'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/excel-'.$index,
                    'idempotency_key' => 'status-excel-'.$index,
                    'release_title' => $title,
                    'artist_name' => $artist,
                    'status' => 'completed',
                    'checkpoint' => 'draft_saved',
                    'soundfresh_workflow_status' => 'under_review',
                    'soundon_release_status' => $soundOnStatus,
                    'soundon_draft_url' => $soundOnUrl,
                    'soundon_check_status' => 'detected',
                    'metadata_snapshot_json' => ['release_date' => $releaseDate],
                ]);
            }

            ReleaseJob::query()->create([
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => 'SF-EXCEL-SCANNING',
                'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/excel-scanning',
                'idempotency_key' => 'status-excel-scanning',
                'release_title' => 'Rilis Tetap Diekspor Saat Scan',
                'artist_name' => 'Artis Saat Scan',
                'status' => 'completed',
                'checkpoint' => 'draft_saved',
                'soundfresh_workflow_status' => 'uploading',
                'soundon_release_status' => 'under_review',
                'soundon_draft_url' => 'https://soundon.example/scanning',
                // refreshSoundOnStatuses temporarily clears this while the new
                // Dimensimatic collection is running. Export must retain the last
                // detected SoundOn status during that window.
                'soundon_check_status' => null,
                'metadata_snapshot_json' => ['release_date' => '2026-09-26'],
            ]);

            $component = Livewire::test(ReleaseStatus::class)
                ->assertSee('Download Rilis Under Review')
                ->call('downloadUnderReviewReport')
                ->assertFileDownloaded('rilis-under-review-2026-09-27.xls');

            $content = $component->effects['download']['content'] ?? '';
            $decoded = base64_decode($content, true) ?: $content;
            $this->assertStringContainsString('Judul Rilis', $decoded);
            $this->assertStringContainsString('Nama Artis', $decoded);
            $this->assertStringContainsString('Tanggal Rilis', $decoded);
            $this->assertStringContainsString('Link SoundOn', $decoded);
            $this->assertStringContainsString('Rilis Hari Ini Excel', $decoded);
            $this->assertStringContainsString('Rilis Terlewat Excel', $decoded);
            $this->assertStringContainsString('Rilis Tetap Diekspor Saat Scan', $decoded);
            $this->assertStringNotContainsString('Rilis Mendatang', $decoded);
            $this->assertStringNotContainsString('Rilis Sudah Live', $decoded);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }
}
