<?php

namespace Tests\Feature;

use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RetryStoppedRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_clears_stop_without_requeueing_other_releases(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => 'stopped', 'stop_requested_at' => now()]);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'test-retry',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/test-retry',
            'idempotency_key' => 'test-retry', 'status' => 'failed',
        ]);
        $this->artisan('soundon:retry', ['releaseJobId' => $job->id])->assertSuccessful();
        $this->assertNull($run->fresh()->stop_requested_at);
        $this->assertSame('running', $run->fresh()->status->value);
        $this->assertSame('queued', $job->fresh()->status->value);
        Queue::assertPushed(ProcessReleaseJob::class, 1);
    }
}
