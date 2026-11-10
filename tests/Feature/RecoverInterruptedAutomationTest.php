<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class RecoverInterruptedAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_releases_the_stale_slot_and_requeues_an_interrupted_release(): void
    {
        Queue::fake();
        Cache::lock('laravel-queue-overlap:soundon-release-global', 1200)->get();
        DB::table('jobs')->insert([
            'queue' => 'release-automation',
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => now()->timestamp,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        $run = AutomationRun::create(['status' => AutomationRunStatus::Running]);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2083',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2083',
            'idempotency_key' => 'recover-2083',
            'status' => ReleaseJobStatus::Running,
        ]);
        $queuedJob = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2082',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2082',
            'idempotency_key' => 'recover-2082',
            'status' => ReleaseJobStatus::Queued,
        ]);

        $this->artisan('dimensimatic:recover-interrupted')->assertSuccessful();

        $this->assertSame(ReleaseJobStatus::Queued, $job->fresh()->status);
        $this->assertTrue(Cache::lock('laravel-queue-overlap:soundon-release-global')->get());
        $this->assertDatabaseCount('jobs', 0);
        Queue::assertPushed(ProcessReleaseJob::class, fn (ProcessReleaseJob $queued) => $queued->releaseJobId === $job->id);
        Queue::assertPushed(ProcessReleaseJob::class, fn (ProcessReleaseJob $queued) => $queued->releaseJobId === $queuedJob->id);
        Queue::assertPushed(ProcessReleaseJob::class, 2);
    }

    public function test_it_repairs_an_invalid_raw_run_status_before_models_are_loaded(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => AutomationRunStatus::Running]);
        DB::table('automation_runs')->where('id', $run->id)->update(['status' => '21']);

        $this->artisan('dimensimatic:recover-interrupted')->assertSuccessful();

        $this->assertDatabaseHas('automation_runs', [
            'id' => $run->id,
            'status' => AutomationRunStatus::Failed->value,
        ]);
        $this->assertNotNull($run->fresh()->finished_at);
    }
}
