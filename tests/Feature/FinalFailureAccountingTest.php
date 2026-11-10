<?php

namespace Tests\Feature;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FinalFailureAccountingTest extends TestCase
{
    use RefreshDatabase;

    public function test_final_failure_is_counted_once_and_closes_run(): void
    {
        $run = AutomationRun::create(['status' => 'running']);
        $release = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => '88', 'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/88', 'idempotency_key' => 'soundfresh:88:soundon', 'status' => 'queued']);
        $job = new ProcessReleaseJob($release->id);
        $job->failed(new RuntimeException('Network timeout'));
        $job->failed(new RuntimeException('Network timeout'));
        $this->assertSame(ReleaseJobStatus::Failed, $release->fresh()->status);
        $this->assertSame(1, $run->fresh()->failed);
        $this->assertSame(AutomationRunStatus::Failed, $run->fresh()->status);
    }
}
