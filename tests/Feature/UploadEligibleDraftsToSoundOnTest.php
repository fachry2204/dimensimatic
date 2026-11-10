<?php

namespace Tests\Feature;

use App\Actions\Automation\UploadEligibleDraftsToSoundOn;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use App\Livewire\Automation\Dashboard;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class UploadEligibleDraftsToSoundOnTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_selected_eligible_releases_are_queued(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => 'completed', 'summary_json' => ['draft_only' => true]]);
        $valid = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/1',
            'idempotency_key' => 'soundfresh:1:soundon',
            'status' => 'completed',
            'metadata_snapshot_json' => ['title' => 'Valid'],
        ]);
        $missing = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2',
            'idempotency_key' => 'soundfresh:2:soundon',
            'status' => 'needs_attention',
        ]);

        $result = app(UploadEligibleDraftsToSoundOn::class)->handle([$valid->id]);

        $this->assertSame(['queued' => 1, 'skipped' => 0], $result);
        $this->assertSame('queued', $valid->fresh()->status->value);
        $this->assertSame('needs_attention', $missing->fresh()->status->value);
        Queue::assertPushed(fn (ProcessReleaseJob $job) => $job->releaseJobId === $valid->id);
        Queue::assertNotPushed(fn (ProcessReleaseJob $job) => $job->releaseJobId === $missing->id);
    }

    public function test_empty_selection_does_not_queue_any_release(): void
    {
        Queue::fake();

        $this->assertSame(['queued' => 0, 'skipped' => 0], app(UploadEligibleDraftsToSoundOn::class)->handle([]));
        Queue::assertNothingPushed();
    }

    public function test_duplicate_release_is_not_included_in_regular_upload_selection(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => 'queued']);
        $duplicate = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'duplicate-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/duplicate-1',
            'idempotency_key' => 'soundfresh:duplicate-1:soundon',
            'status' => 'needs_attention',
            'error_code' => 'DUPLICATE_RELEASE_FOUND',
            'error_message' => 'Tidak dapat di-upload: judul dan artis yang sama ditemukan di SoundOn Drafts.',
        ]);

        $this->assertSame(
            ['queued' => 0, 'skipped' => 1],
            app(UploadEligibleDraftsToSoundOn::class)->handle([$duplicate->id]),
        );
        $this->assertSame('needs_attention', $duplicate->fresh()->status->value);
        Queue::assertNothingPushed();
    }

    public function test_operator_can_force_a_duplicate_release_to_upload(): void
    {
        Queue::fake();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $run = AutomationRun::create(['status' => 'completed', 'summary_json' => ['duplicate_check_completed' => true]]);
        $duplicate = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'duplicate-force-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/duplicate-force-1',
            'idempotency_key' => 'soundfresh:duplicate-force-1:soundon',
            'release_title' => 'Rilisan Duplikat',
            'status' => 'needs_attention',
            'error_code' => 'DUPLICATE_RELEASE_FOUND',
            'error_message' => 'Rilisan yang sama ditemukan.',
        ]);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->call('forceDuplicateUpload', $duplicate->id)
            ->assertSet('operationError', null)
            ->assertSee('Rilisan Duplikat diantrikan untuk tetap di-upload ke SoundOn.');

        $duplicate->refresh();
        $this->assertSame('queued', $duplicate->status->value);
        $this->assertNull($duplicate->error_code);
        $this->assertContains((string) $duplicate->id, $run->fresh()->summary_json['force_duplicate_upload_job_ids']);
        Queue::assertPushed(fn (ProcessReleaseJob $job) => $job->releaseJobId === $duplicate->id);
    }

    public function test_dashboard_explains_that_upload_is_automatic(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('Upload otomatis aktif')
            ->assertSee('Semua rilisan nonduplikat langsung di-upload ke SoundOn')
            ->assertDontSee('Upload pilihan (0)')
            ->assertDontSee('Centang rilisan pada tabel');
    }
}
