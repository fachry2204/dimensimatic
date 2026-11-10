<?php

namespace Tests\Feature;

use App\Actions\Automation\ClearAutomationMonitor;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClearAutomationMonitorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_removes_pipeline_monitor_data_without_stopping_status_checks(): void
    {
        config(['automation.hmac_key' => 'test-secret', 'automation.worker_url' => 'http://127.0.0.1:3100']);
        Http::fake(['*/v1/jobs/*/cancel' => Http::response(['success' => true, 'data' => ['cancelled' => true]])]);
        $pipelineRun = AutomationRun::create(['status' => 'running']);
        $pipelineJob = ReleaseJob::create([
            'automation_run_id' => $pipelineRun->id,
            'idempotency_key' => 'soundfresh:1:soundon',
            'soundfresh_release_id' => '1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/1',
            'status' => 'running',
        ]);
        AutomationEvent::create([
            'automation_run_id' => $pipelineRun->id,
            'release_job_id' => $pipelineJob->id,
            'event' => 'test',
            'message' => 'old status',
        ]);
        DB::table('jobs')->insert([
            'queue' => 'release-automation',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);
        DB::table('jobs')->insert([
            'queue' => 'status-checks',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->timestamp,
            'created_at' => now()->timestamp,
        ]);

        $statusRun = AutomationRun::create([
            'status' => 'running',
            'summary_json' => ['purpose' => 'soundon_status_check'],
        ]);
        $statusJob = ReleaseJob::create([
            'automation_run_id' => $statusRun->id,
            'idempotency_key' => 'soundfresh:status-1:soundon',
            'soundfresh_release_id' => 'status-1',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/status-1',
            'status' => 'completed',
            'soundon_check_status' => 'checking',
            'soundon_check_progress' => 20,
        ]);

        app(ClearAutomationMonitor::class)->handle();

        $this->assertDatabaseMissing('automation_runs', ['id' => $pipelineRun->id]);
        $this->assertDatabaseMissing('release_jobs', ['id' => $pipelineJob->id]);
        $this->assertDatabaseHas('automation_runs', ['id' => $statusRun->id]);
        $this->assertDatabaseHas('release_jobs', ['id' => $statusJob->id, 'soundon_check_status' => 'checking']);
        $this->assertDatabaseEmpty('automation_events');
        $this->assertDatabaseMissing('jobs', ['queue' => 'release-automation']);
        $this->assertDatabaseHas('jobs', ['queue' => 'status-checks']);
        Http::assertSent(fn ($request) => str_contains($request->url(), "/v1/jobs/{$pipelineJob->id}/cancel"));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), "/v1/jobs/{$statusJob->id}/cancel"));
    }
}
