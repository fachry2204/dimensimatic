<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use Illuminate\Support\Facades\DB;

final class PauseAutomationRun
{
    public function handle(AutomationRun $run): void
    {
        // This action must return immediately. Calling the browser worker here
        // can hold PHP's single desktop web server for minutes.
        DB::transaction(function () use ($run): void {
            $pausedAt = now();

            // The dashboard pause control is a safety control for the local
            // automation, not merely one table row. Pause every active run so
            // no second queue/browser operation can continue in the
            // background after the operator presses Pause.
            AutomationRun::query()
                ->whereIn('status', [AutomationRunStatus::Running, AutomationRunStatus::Queued])
                ->update(['status' => AutomationRunStatus::Paused]);
            ReleaseJob::query()
                ->whereIn('status', [ReleaseJobStatus::Running, ReleaseJobStatus::Queued])
                ->update([
                    'status' => ReleaseJobStatus::Stopped,
                    'finished_at' => null,
                    'updated_at' => $pausedAt,
                ]);

            // A database queue message is consumed even when ProcessReleaseJob
            // exits because its run is paused. Remove those stale messages;
            // Continue will dispatch a fresh message for every stopped job.
            DB::table('jobs')->where('queue', 'release-automation')->delete();
        });
    }
}
