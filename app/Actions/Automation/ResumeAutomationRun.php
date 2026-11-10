<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Support\Facades\DB;

final class ResumeAutomationRun
{
    public function handle(AutomationRun $run): void
    {
        // Resume this run only. Deleting reserved messages does not cancel
        // their browser operations and used to strand jobs on Retry/Resume.
        // Per-release locks and the atomic queued claim prevent double work.

        $jobIds = DB::transaction(function () use ($run): array {
            $run->update([
                'status' => AutomationRunStatus::Running,
                'stop_requested_at' => null,
                'finished_at' => null,
            ]);

            $selectedJobIds = collect(data_get($run->summary_json, 'selected_release_job_ids', []))
                ->map('strval')
                ->filter()
                ->unique()
                ->values();

            $jobsQuery = $run->releaseJobs()
                ->whereIn('status', [ReleaseJobStatus::Stopped, ReleaseJobStatus::Queued, ReleaseJobStatus::Failed]);

            // Runs created by the selectable-upload dashboard must resume only
            // releases that the operator explicitly selected. Unselected rows
            // remain monitor entries and are not silently uploaded.
            if ($selectedJobIds->isNotEmpty()) {
                $jobsQuery->whereIn('id', $selectedJobIds);
            }

            $jobs = $jobsQuery->get();

            $run->releaseJobs()
                ->whereIn('id', $jobs->pluck('id'))
                ->update([
                    'status' => ReleaseJobStatus::Queued,
                    'finished_at' => null,
                    'updated_at' => now(),
                ]);

            return $jobs->pluck('id')->all();
        });

        foreach ($jobIds as $jobId) {
            ProcessReleaseJob::dispatch($jobId);
        }
    }
}
