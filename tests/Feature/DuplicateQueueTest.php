<?php
namespace Tests\Feature;

use App\Jobs\CheckPendingReleaseDuplicates;
use App\Jobs\ProcessReleaseJob;
use App\Livewire\Automation\Dashboard;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Models\User;
use App\Services\Automation\PlaywrightClient;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class DuplicateQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_check_uses_the_pipeline_queue_and_failure_clears_waiting(): void
    {
        $run = AutomationRun::create(['status' => 'running']);
        $release = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'q1', 'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/q1', 'idempotency_key' => 'q1', 'status' => 'queued', 'error_code' => 'DUPLICATE_CHECK_PENDING']);
        $check = new CheckPendingReleaseDuplicates([$release->id], $run->id);
        $this->assertSame('release-automation', $check->queue);
        $check->failed(new \RuntimeException('Timeout'));
        $this->assertSame('DUPLICATE_CHECK_FAILED', $release->fresh()->error_code);
    }

    public function test_upload_passes_completed_initial_check_to_browser(): void
    {
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);
        app(PlaywrightClient::class)->createDraft('test', [], null, true);
        Http::assertSent(fn ($request) => $request['duplicates_checked'] === true);
    }

    public function test_duplicate_check_preserves_the_worker_cause_instead_of_generic_internal_server_error(): void
    {
        config(['automation.hmac_key' => 'test', 'automation.worker_url' => 'http://worker.test']);
        Http::fake([
            'worker.test/v1/soundfresh/pending' => Http::response([
                'statusCode' => 500,
                'error' => 'Internal Server Error',
                'message' => 'locator.click: Timeout 20000ms exceeded',
            ], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('locator.click: Timeout 20000ms exceeded');

        app(PlaywrightClient::class)->soundfreshDuplicates([
            ['key' => 'release-1', 'title' => 'Single', 'artist' => 'Artist'],
        ]);
    }

    public function test_operator_can_force_upload_after_a_duplicate_is_found(): void
    {
        Queue::fake();
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $run = AutomationRun::create(['status' => 'completed', 'summary_json' => ['duplicate_check_completed' => true]]);
        $release = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'force-duplicate-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/force-duplicate-1',
            'idempotency_key' => 'force-duplicate-1',
            'release_title' => 'Rilisan Duplikat',
            'status' => 'needs_attention',
            'error_code' => 'DUPLICATE_RELEASE_FOUND',
            'error_message' => 'Rilisan dengan judul dan artis yang sama ditemukan.',
        ]);

        Livewire::actingAs($admin)
            ->test(Dashboard::class)
            ->assertSee('Tetap Upload')
            ->call('forceDuplicateUpload', $release->id)
            ->assertSet('operationError', null)
            ->assertSee('Rilisan Duplikat diantrikan untuk tetap di-upload ke SoundOn.');

        $release->refresh();
        $this->assertSame('queued', $release->status->value);
        $this->assertNull($release->error_code);
        $this->assertContains((string) $release->id, $run->fresh()->summary_json['force_duplicate_upload_job_ids']);
        Queue::assertPushed(fn (ProcessReleaseJob $job) => $job->releaseJobId === $release->id);
    }
}
