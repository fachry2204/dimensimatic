<?php

namespace Tests\Feature;

use App\Actions\Automation\PauseAutomationRun;
use App\Actions\Automation\ResumeAutomationRun;
use App\Actions\Automation\StopAutomationRun;
use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AutomationControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_buttons_handle_missing_credentials_without_type_errors(): void
    {
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
        $user = \App\Models\User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        \Livewire\Livewire::test(\App\Livewire\Automation\Dashboard::class)->call('startQueueWorker')
            ->assertSet('showWorkerOperationModal', false)
            ->assertSee('Credential Dimensi Suara belum dikonfigurasi');
        \Livewire\Livewire::test(\App\Livewire\Automation\Dashboard::class)->call('startCheckWorker')
            ->assertSet('showWorkerOperationModal', false)
            ->assertSee('Credential Dimensi Suara belum lengkap');
    }

    public function test_pending_button_reaches_collection_with_readable_credentials(): void
    {
        $this->seed(\Database\Seeders\AccessControlSeeder::class);
        $user = \App\Models\User::factory()->create();
        $user->assignRole('Admin');
        $this->actingAs($user);
        \App\Models\AutomationAccount::create([
            'platform' => 'soundfresh', 'name' => 'Dimensimatic', 'status' => 'active',
            'email_encrypted' => 'probe@example.com', 'password_encrypted' => 'test-password',
            'session_state_encrypted' => ['cookies' => [], 'origins' => []],
            'last_authenticated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['success' => true, 'data' => ['items' => []]]),
        ]);
        \Livewire\Livewire::test(\App\Livewire\Automation\Dashboard::class)->call('start')
            ->assertSet('operationError', null)->assertSet('configurationError', null);
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) =>
            str_ends_with($request->url(), '/v1/soundfresh/pending')
            && $request['action'] === 'list_pending_releases');
        $this->assertDatabaseHas('automation_runs', ['status' => 'completed_no_pending']);
    }

    public function test_run_can_be_paused_and_stopped(): void
    {
        $run = AutomationRun::create(['status' => 'running']);
        $queued = $this->release($run, '1', ReleaseJobStatus::Queued);
        $running = $this->release($run, '2', ReleaseJobStatus::Running);
        $secondRun = AutomationRun::create(['status' => 'queued']);
        $secondRunJob = $this->release($secondRun, '4', ReleaseJobStatus::Queued);
        $running->update(['soundon_check_status' => 'checking', 'soundon_check_progress' => 20]);
        $completed = $this->release($run, '3', ReleaseJobStatus::Completed);

        app(PauseAutomationRun::class)->handle($run);
        $this->assertSame(AutomationRunStatus::Paused, $run->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Stopped, $queued->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Stopped, $running->fresh()->status);
        $this->assertSame(AutomationRunStatus::Paused, $secondRun->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Stopped, $secondRunJob->fresh()->status);
        app(StopAutomationRun::class)->handle($run);
        $this->assertNotNull($run->fresh()->stop_requested_at);
        $this->assertSame(AutomationRunStatus::Stopped, $run->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Stopped, $queued->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Stopped, $running->fresh()->status);
        $this->assertSame('stopped', $running->fresh()->soundon_check_status);
        $this->assertSame(100, $running->fresh()->soundon_check_progress);
        $this->assertNotNull($running->fresh()->soundon_check_finished_at);
        $this->assertSame(ReleaseJobStatus::Completed, $completed->fresh()->status);
    }

    public function test_paused_release_jobs_are_dispatched_again_on_continue(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => AutomationRunStatus::Running]);
        $running = $this->release($run, '4', ReleaseJobStatus::Running);
        $queued = $this->release($run, '5', ReleaseJobStatus::Queued);
        $run->update(['summary_json' => ['selected_release_job_ids' => [$running->id, $queued->id]]]);

        app(PauseAutomationRun::class)->handle($run);
        app(ResumeAutomationRun::class)->handle($run->fresh());

        $this->assertSame(AutomationRunStatus::Running, $run->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Queued, $running->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Queued, $queued->fresh()->status);
        Queue::assertPushed(ProcessReleaseJob::class, 2);
    }

    public function test_stopped_release_jobs_continue_from_the_queue(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => AutomationRunStatus::Stopped, 'stop_requested_at' => now(), 'finished_at' => now()]);
        $first = $this->release($run, '10', ReleaseJobStatus::Stopped);
        $second = $this->release($run, '11', ReleaseJobStatus::Stopped);
        $completed = $this->release($run, '12', ReleaseJobStatus::Completed);
        $run->update(['summary_json' => ['selected_release_job_ids' => [$first->id, $second->id]]]);

        app(ResumeAutomationRun::class)->handle($run);

        $this->assertSame(AutomationRunStatus::Running, $run->fresh()->status);
        $this->assertNull($run->fresh()->stop_requested_at);
        $this->assertSame(ReleaseJobStatus::Queued, $first->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Queued, $second->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Completed, $completed->fresh()->status);
        Queue::assertPushed(ProcessReleaseJob::class, 2);
    }

    public function test_continue_does_not_upload_unselected_stopped_releases(): void
    {
        Queue::fake();
        $run = AutomationRun::create(['status' => AutomationRunStatus::Stopped, 'stop_requested_at' => now()]);
        $selected = $this->release($run, '20', ReleaseJobStatus::Stopped);
        $unselected = $this->release($run, '21', ReleaseJobStatus::Stopped);
        $run->update(['summary_json' => ['selected_release_job_ids' => [$selected->id]]]);

        app(ResumeAutomationRun::class)->handle($run);

        $this->assertSame(ReleaseJobStatus::Queued, $selected->fresh()->status);
        $this->assertSame(ReleaseJobStatus::Stopped, $unselected->fresh()->status);
        Queue::assertPushed(ProcessReleaseJob::class, 1);
        Queue::assertPushed(fn (ProcessReleaseJob $job): bool => $job->releaseJobId === $selected->id);
    }

    private function release(AutomationRun $run, string $releaseId, ReleaseJobStatus $status): ReleaseJob
    {
        return ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => $releaseId,
            'soundfresh_release_url' => "https://cms.soundfresh.id/admin/releases/{$releaseId}",
            'idempotency_key' => "soundfresh:{$releaseId}:soundon",
            'status' => $status,
        ]);
    }
}
