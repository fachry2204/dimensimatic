<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ClearAutomationMonitor
{
    public function __construct(private readonly PlaywrightClient $worker)
    {
    }

    public function handle(): void
    {
        // Status checks and identifier verification are a separate operation
        // from collecting Pending releases.  The dashboard used to clear the
        // whole jobs table here, which silently cancelled a status check every
        // time the operator clicked "Ambil semua rilisan pending".
        $statusRunIds = AutomationRun::query()
            ->where('summary_json->purpose', 'soundon_status_check')
            ->pluck('id');
        $activeStatusRunIds = ReleaseJob::query()
            ->where(function ($query): void {
                $query->whereIn('soundon_check_status', ['queued', 'checking'])
                    ->orWhereIn('soundfresh_verify_status', ['sending', 'rejecting']);
            })
            ->pluck('automation_run_id');
        $protectedRunIds = $statusRunIds->merge($activeStatusRunIds)->filter()->unique()->values();
        $pipelineRunIds = AutomationRun::query()
            ->when($protectedRunIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $protectedRunIds))
            ->pluck('id');
        $pipelineJobIds = ReleaseJob::query()
            ->when($pipelineRunIds->isNotEmpty(), fn ($query) => $query->whereIn('automation_run_id', $pipelineRunIds))
            ->pluck('id');

        // Stop only pipeline browser tasks before removing their records.
        // Status-check contexts intentionally remain alive in the worker.
        ReleaseJob::query()
            ->when($pipelineJobIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $pipelineJobIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->where('status', 'running')
            ->pluck('id')
            ->each(function (string $jobId): void {
                try {
                    $this->worker->cancel($jobId);
                } catch (Throwable) {
                    // Clearing the local monitor must still succeed when the
                    // worker is already offline or has completed the task.
                }
            });

        AutomationRun::query()
            ->when($pipelineRunIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $pipelineRunIds), fn ($query) => $query->whereRaw('1 = 0'))
            ->update([
            'status' => AutomationRunStatus::Cancelled,
            'stop_requested_at' => now(),
            'finished_at' => now(),
        ]);

        DB::transaction(function () use ($pipelineJobIds, $pipelineRunIds): void {
            if (Schema::hasTable('jobs')) {
                DB::table('jobs')->where('queue', 'release-automation')->delete();
            }

            if (Schema::hasTable('failed_jobs')) {
                DB::table('failed_jobs')
                    ->where(function ($query): void {
                        $query->where('payload', 'like', '%ProcessReleaseJob%')
                            ->orWhere('payload', 'like', '%CollectPendingSoundfreshReleases%')
                            ->orWhere('payload', 'like', '%CheckPendingReleaseDuplicates%');
                    })
                    ->delete();
            }

            if ($pipelineJobIds->isNotEmpty()) {
                DB::table('automation_artifacts')->whereIn('release_job_id', $pipelineJobIds)->delete();
                DB::table('automation_events')->whereIn('release_job_id', $pipelineJobIds)->delete();
            }
            if ($pipelineRunIds->isNotEmpty()) {
                DB::table('automation_artifacts')->whereIn('automation_run_id', $pipelineRunIds)->delete();
                DB::table('automation_events')->whereIn('automation_run_id', $pipelineRunIds)->delete();
                DB::table('automation_runs')->whereIn('id', $pipelineRunIds)->delete();
            }
        });
    }
}
