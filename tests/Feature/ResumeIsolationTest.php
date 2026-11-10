<?php

namespace Tests\Feature;

use App\Actions\Automation\ResumeAutomationRun;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResumeIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_resume_preserves_other_runs_and_reserved_messages(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => 'paused']);
        $other = AutomationRun::create(['status' => 'running']);
        foreach ([$run, $other] as $index => $owner) {
            ReleaseJob::create([
                'automation_run_id' => $owner->id,
                'soundfresh_release_id' => (string) $index,
                'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/'.$index,
                'idempotency_key' => 'resume-test-'.$index,
                'status' => $index === 0 ? 'failed' : 'running',
            ]);
        }
        $message = DB::table('jobs')->insertGetId([
            'queue' => 'release-automation', 'payload' => '{}', 'attempts' => 1,
            'reserved_at' => time(), 'available_at' => time(), 'created_at' => time(),
        ]);
        app(ResumeAutomationRun::class)->handle($run);
        $this->assertSame('running', $other->fresh()->status->value);
        $this->assertSame('running', $other->releaseJobs()->first()->status->value);
        $this->assertDatabaseHas('jobs', ['id' => $message]);
        Queue::assertPushed(ProcessReleaseJob::class, 1);
    }
}
