<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Enums\ReleaseJobStatus;
use App\Enums\AutomationRunStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationEvent;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\OperatorAudit;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class RunDetail extends Component
{
    public AutomationRun $run;

    public function mount(AutomationRun $run): void
    {
        $this->run = $run;
    }

    public function retry(string $jobId): void
    {
        Gate::authorize('automation.retry');
        $job = ReleaseJob::where('automation_run_id', $this->run->id)->findOrFail($jobId);
        abort_unless(in_array($job->status, [ReleaseJobStatus::Failed, ReleaseJobStatus::NeedsAttention], true), 409);
        $job->update(['status' => ReleaseJobStatus::Queued, 'error_code' => null, 'error_message' => null, 'finished_at' => null]);
        $this->run->update(['status' => AutomationRunStatus::Running, 'stop_requested_at' => null, 'finished_at' => null]);
        app(OperatorAudit::class)->record('job_retry_requested', 'Operator requested release job retry.', $this->run->id, $job->id);
        ProcessReleaseJob::dispatch($job->id);
    }

    public function render()
    {
        return view('livewire.automation.run-detail', [
            'jobs' => $this->run->releaseJobs()->latest()->get(),
            'events' => AutomationEvent::where('automation_run_id', $this->run->id)->latest('created_at')->limit(100)->get(),
        ]);
    }
}
