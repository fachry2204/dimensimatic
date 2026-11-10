<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Support\Facades\DB;

final class StopAutomationRun
{
    public function handle(AutomationRun $run): void
    {
        // Persist the stop signal locally first; browser cancellation must
        // never block the Livewire request or freeze every page.
        DB::transaction(function (): void {
            $stoppedAt = now();

            AutomationRun::query()
                ->whereIn('status', [AutomationRunStatus::Running, AutomationRunStatus::Queued, AutomationRunStatus::Paused])
                ->update([
                'status' => AutomationRunStatus::Stopped,
                'stop_requested_at' => $stoppedAt,
                'finished_at' => $stoppedAt,
            ]);

            ReleaseJob::query()
                ->whereNotIn('status', [ReleaseJobStatus::Completed, ReleaseJobStatus::Skipped])
                ->update([
                    'status' => ReleaseJobStatus::Stopped,
                    'finished_at' => $stoppedAt,
                    'updated_at' => $stoppedAt,
                ]);
            ReleaseJob::query()
                ->whereIn('soundon_check_status', ['queued', 'checking'])
                ->update([
                    'soundon_check_status' => 'stopped',
                    'soundon_check_progress' => 100,
                    'soundon_check_error' => 'Pemeriksaan dihentikan oleh operator.',
                    'soundon_status_checked_at' => $stoppedAt,
                    'soundon_check_finished_at' => $stoppedAt,
                    'updated_at' => $stoppedAt,
                ]);
            ReleaseJob::query()
                ->whereIn('soundfresh_verify_status', ['sending', 'rejecting'])
                ->update([
                    'soundfresh_verify_status' => 'failed',
                    'soundfresh_verify_error' => 'Dihentikan oleh operator.',
                    'updated_at' => $stoppedAt,
                ]);
            DB::table('jobs')->whereIn('queue', ['release-automation', 'status-checks'])->delete();
        });
    }
}
