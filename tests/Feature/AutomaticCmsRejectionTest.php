<?php

namespace Tests\Feature;

use App\Jobs\CheckSoundOnReleaseStatus;
use App\Jobs\RejectSoundfreshRelease;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\QueueCmsRejection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomaticCmsRejectionTest extends TestCase
{
    use RefreshDatabase;

    private function release(array $attributes = []): ReleaseJob
    {
        $run = AutomationRun::create(['status' => 'completed']);
        return ReleaseJob::create(array_merge([
            'automation_run_id' => $run->id, 'status' => 'completed', 'checkpoint' => 'completed',
            'soundfresh_release_id' => 'reject-123', 'soundfresh_release_url' => 'https://dimensisuara.id/admin/releases/123',
            'idempotency_key' => 'reject-'.$run->id, 'release_title' => 'Love Grows Like Ivy',
            'soundfresh_workflow_status' => 'uploading', 'soundon_check_status' => 'detected',
            'soundon_release_status' => 'not_approved', 'soundon_rejection_reason' => "Artwork: unreadable text\nAudio: incorrect instrumental flag",
        ], $attributes));
    }

    private function account(string $platform): void
    {
        AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active',
            'last_authenticated_at' => now(), 'session_state_encrypted' => ['cookies' => []]]);
    }

    public function test_status_check_automatically_queues_the_exact_soundon_reason_once(): void
    {
        Queue::fake();
        $this->account('soundon');
        $job = $this->release();
        $reason = $job->soundon_rejection_reason;
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['matches' => [
            $job->id => ['found' => true, 'status' => 'Rejected', 'reason' => $reason],
        ]]])]);
        $check = new CheckSoundOnReleaseStatus($job->id);
        app()->call([$check, 'handle']);
        app()->call([$check, 'handle']);
        app(QueueCmsRejection::class)->dispatchDetected();
        Queue::assertPushedOn('cms-verification', RejectSoundfreshRelease::class);
        Queue::assertPushed(RejectSoundfreshRelease::class, 1);
        Queue::assertPushed(RejectSoundfreshRelease::class, fn ($queued) => $queued->reason === $reason);
        $this->assertSame('rejecting', $job->fresh()->soundfresh_verify_status);
    }

    public function test_missing_reason_is_reported_and_no_rejection_is_sent(): void
    {
        Queue::fake();
        $job = $this->release(['soundon_rejection_reason' => null]);
        $this->assertFalse(app(QueueCmsRejection::class)->dispatch($job));
        Queue::assertNothingPushed();
        $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
        $this->assertStringContainsString('Alasan penolakan SoundOn belum terbaca', $job->fresh()->soundfresh_verify_error);
    }

    public function test_poll_recovers_a_detected_rejection_into_the_durable_cms_queue_once(): void
    {
        config(['queue.default' => 'database']);
        $job = $this->release();
        $page = new \App\Livewire\Automation\ReleaseStatus;
        $page->pollStatusUpdates();
        $page->pollStatusUpdates();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'cms-verification']);
        $this->assertSame('rejecting', $job->fresh()->soundfresh_verify_status);
    }

    public function test_queue_failure_rolls_back_the_rejection_claim(): void
    {
        $job = $this->release();
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class)->shouldReceive('dispatch')->once()
            ->andThrow(new \RuntimeException('Queue unavailable'));
        try {
            app(QueueCmsRejection::class)->dispatch($job);
            $this->fail('Queue exception expected');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertNull($job->fresh()->soundfresh_verify_status);
    }

    public function test_job_records_rejected_only_after_worker_confirmation(): void
    {
        $this->account('soundfresh');
        $job = $this->release(['soundfresh_verify_status' => 'rejecting']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['rejected' => true]])]);
        app()->call([new RejectSoundfreshRelease($job->id, $job->soundon_rejection_reason), 'handle']);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/v1/soundfresh/releases/reject')
            && $request['reason'] === $job->soundon_rejection_reason && $request['release_url'] === $job->soundfresh_release_url);
        $this->assertSame('rejected', $job->fresh()->soundfresh_workflow_status);
        $this->assertSame('rejected', $job->fresh()->soundfresh_verify_status);
        $this->assertNotNull($job->fresh()->soundfresh_rejected_at);
    }

    public function test_cancelled_rejection_does_not_contact_the_worker_and_late_failure_preserves_stop(): void
    {
        Http::fake();
        $job = $this->release(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Dihentikan oleh operator']);
        $reject = new RejectSoundfreshRelease($job->id, $job->soundon_rejection_reason);
        app()->call([$reject, 'handle']);
        $reject->failed(new \RuntimeException('Late timeout'));
        Http::assertNothingSent();
        $this->assertSame('Dihentikan oleh operator', $job->fresh()->soundfresh_verify_error);
    }

    public function test_unconfirmed_rejection_is_a_failure_and_can_be_retried_by_a_new_check(): void
    {
        $this->account('soundfresh');
        $job = $this->release(['soundfresh_verify_status' => 'rejecting']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['rejected' => false]])]);
        app()->call([new RejectSoundfreshRelease($job->id, $job->soundon_rejection_reason), 'handle']);
        $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
        $this->assertSame('uploading', $job->fresh()->soundfresh_workflow_status);
        Queue::fake();
        $this->assertFalse(app(QueueCmsRejection::class)->dispatch($job));
        $this->assertTrue(app(QueueCmsRejection::class)->dispatch($job, true));
        Queue::assertPushed(RejectSoundfreshRelease::class, 1);
    }
}
