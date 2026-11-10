<?php

namespace Tests\Feature;

use App\Jobs\CheckSoundOnReleaseStatus;
use App\Jobs\VerifySoundfreshReleaseIdentifiers;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomaticCmsVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $platform): void
    {
        AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active',
            'last_authenticated_at' => now(), 'session_state_encrypted' => ['cookies' => []]]);
    }

    private function release(string $key, array $attributes = []): ReleaseJob
    {
        $run = AutomationRun::firstOrCreate([], ['status' => 'completed']);

        return ReleaseJob::create(array_merge([
            'automation_run_id' => $run->id, 'status' => 'completed', 'checkpoint' => 'completed',
            'soundfresh_release_id' => $key, 'soundfresh_release_url' => 'https://dimensisuara.id/admin/releases/'.$key,
            'idempotency_key' => 'auto-verify-'.$key, 'release_title' => 'Release '.$key,
            'soundfresh_workflow_status' => 'uploading', 'track_count' => 1,
            'soundon_release_status' => 'approved', 'soundon_upc' => '1234567890123',
            'soundon_isrcs_json' => ['IDABC2612345'],
        ], $attributes));
    }

    public function test_previously_detected_identifiers_are_dispatched_once_without_retrying_failures(): void
    {
        Queue::fake();
        $ready = $this->release('ready', ['soundon_check_status' => 'detected']);
        $failed = $this->release('failed', ['soundon_check_status' => 'detected', 'soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Dihentikan oleh operator']);
        $incomplete = $this->release('incomplete', ['soundon_check_status' => 'detected', 'track_count' => 2]);
        $service = app(\App\Services\Automation\QueueCmsVerification::class);
        $service->dispatchDetected();
        $service->dispatchDetected();
        Queue::assertPushedOn('cms-verification', VerifySoundfreshReleaseIdentifiers::class);
        Queue::assertPushed(VerifySoundfreshReleaseIdentifiers::class, 1);
        $this->assertSame('sending', $ready->fresh()->soundfresh_verify_status);
        $this->assertSame('failed', $failed->fresh()->soundfresh_verify_status);
        $this->assertNull($incomplete->fresh()->soundfresh_verify_status);
    }

    public function test_failed_queue_insertion_rolls_back_the_sending_status(): void
    {
        $job = $this->release('rollback');
        $this->mock(\Illuminate\Contracts\Bus\Dispatcher::class)
            ->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('Queue unavailable'));
        try {
            app(\App\Services\Automation\QueueCmsVerification::class)->dispatch($job);
            $this->fail('Queue exception was expected');
        } catch (\RuntimeException $error) {
            $this->assertSame('Queue unavailable', $error->getMessage());
        }
        $this->assertNull($job->fresh()->soundfresh_verify_status);
    }

    public function test_worker_failure_marks_sending_failed_but_preserves_operator_cancellation(): void
    {
        $job = $this->release('timeout', ['soundfresh_verify_status' => 'sending']);
        $verify = new VerifySoundfreshReleaseIdentifiers($job->id);
        $verify->failed(new \RuntimeException('Timeout'));
        $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
        $job->update(['soundfresh_verify_error' => 'Dihentikan oleh operator']);
        $verify->failed(new \RuntimeException('Late error'));
        $this->assertSame('Dihentikan oleh operator', $job->fresh()->soundfresh_verify_error);
    }

    public function test_poll_recovers_detected_release_into_a_dedicated_durable_queue(): void
    {
        config(['queue.default' => 'database']);
        $job = $this->release('poll', ['soundon_check_status' => 'detected']);
        $page = new \App\Livewire\Automation\ReleaseStatus;
        $page->pollStatusUpdates();
        $page->pollStatusUpdates();
        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseHas('jobs', ['queue' => 'cms-verification']);
        $this->assertSame('sending', $job->fresh()->soundfresh_verify_status);
    }

    public function test_cms_failure_is_visible_next_to_detection_status(): void
    {
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
        $viewer = \App\Models\User::factory()->create();
        $viewer->assignRole('Viewer');
        $this->actingAs($viewer);
        $this->release('visible-error', ['soundon_check_status' => 'detected', 'soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Sesi CMS perlu login ulang']);
        \Livewire\Livewire::test(\App\Livewire\Automation\ReleaseStatus::class)
            ->assertSee('Terdeteksi')
            ->assertSee('CMS: pengiriman gagal')
            ->assertSee('Sesi CMS perlu login ulang');
    }

    public function test_approved_delivered_and_live_queue_verification_once_but_review_and_rejection_do_not(): void
    {
        Queue::fake();
        $this->account('soundon');
        $matches = [];
        $jobs = [];
        foreach (['Approved', 'Aproved', 'Delivered', 'Live', 'Under Review', 'Not Approved'] as $index => $status) {
            $job = $this->release((string) $index);
            $jobs[] = $job;
            $matches[$job->id] = ['found' => true, 'status' => $status, 'upc' => '1234567890123', 'isrcs' => ['IDABC2612345']];
        }
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['matches' => $matches]])]);
        $check = new CheckSoundOnReleaseStatus(array_map(fn ($job) => $job->id, $jobs));
        app()->call([$check, 'handle']);
        Queue::assertPushed(VerifySoundfreshReleaseIdentifiers::class, 4);
        foreach (array_slice($jobs, 0, 4) as $job) {
            $this->assertSame('sending', $job->fresh()->soundfresh_verify_status);
        }
        foreach (array_slice($jobs, 4) as $job) {
            $this->assertNull($job->fresh()->soundon_upc);
            $this->assertNull($job->fresh()->soundfresh_verify_status);
        }
        app()->call([$check, 'handle']);
        Queue::assertPushed(VerifySoundfreshReleaseIdentifiers::class, 4);
    }

    public function test_verification_sends_soundon_identifiers_and_only_marks_success_after_worker_confirmation(): void
    {
        $this->account('soundfresh');
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['verified' => true]])]);
        foreach (['approved', 'delivery', 'live'] as $status) {
            $job = $this->release($status, ['soundon_release_status' => $status, 'soundon_isrcs_json' => [' idabc2612345 ']]);
            $verify = new VerifySoundfreshReleaseIdentifiers($job->id);
            app()->call([$verify, 'handle']);
            $this->assertSame('success', $job->fresh()->soundfresh_verify_status);
            $this->assertSame('verified', $job->fresh()->soundfresh_workflow_status);
            $this->assertNotNull($job->fresh()->soundfresh_verified_at);
            app()->call([$verify, 'handle']);
        }
        Http::assertSentCount(3);
        foreach (Http::recorded() as [$request]) {
            $this->assertStringEndsWith('/v1/soundfresh/releases/verify-identifiers', $request->url());
            $this->assertSame('1234567890123', $request['upc']);
            $this->assertSame(['IDABC2612345'], $request['isrcs']);
        }
    }

    public function test_pending_or_rejected_status_and_invalid_or_incomplete_codes_never_reach_the_cms(): void
    {
        Http::fake();
        foreach ([
            ['soundon_release_status' => 'under_review'], ['soundon_release_status' => 'not_approved'],
            ['soundon_upc' => 'invalid'], ['soundon_isrcs_json' => ['wrong']],
            ['track_count' => 2, 'soundon_isrcs_json' => ['IDABC2612345', '']],
            ['track_count' => 2, 'soundon_isrcs_json' => ['IDABC2612345']],
        ] as $index => $values) {
            $job = $this->release('invalid-'.$index, $values);
            app()->call([new VerifySoundfreshReleaseIdentifiers($job->id), 'handle']);
            $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
            $this->assertNull($job->fresh()->soundfresh_verified_at);
        }
        Http::assertNothingSent();
    }

    public function test_a_closed_or_unconfirmed_remote_form_never_marks_cms_verified(): void
    {
        $this->account('soundfresh');
        $job = $this->release('unconfirmed');
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['verified' => false]])]);
        app()->call([new VerifySoundfreshReleaseIdentifiers($job->id), 'handle']);
        $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
        $this->assertSame('uploading', $job->fresh()->soundfresh_workflow_status);
        $this->assertNull($job->fresh()->soundfresh_verified_at);
    }
}
