<?php

namespace App\Actions\Automation;

use App\Models\AutomationRun;
use App\Models\AutomationSetting;
use App\Models\PublishRelease;
use App\Models\ReleaseJob;
use App\Services\Automation\LocalSystemManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StopWorkForUpdate
{
    public function handle(): void
    {
        $status = app(LocalSystemManager::class)->stopAllWorkers();
        if ($status['worker'] || $status['release_workers'] || $status['status_workers'] || $status['cms_workers']) {
            throw new RuntimeException('Sebagian worker belum berhenti. Installer tidak dijalankan.');
        }
        DB::transaction(function () {
            AutomationSetting::current()->update(['automatic_run_enabled' => false]);
            AutomationRun::whereIn('status', ['running', 'queued', 'paused'])->update(['status' => 'stopped', 'stop_requested_at' => now(), 'finished_at' => now()]);
            ReleaseJob::whereIn('status', ['running', 'queued'])->update(['status' => 'stopped', 'finished_at' => now()]);
            ReleaseJob::whereIn('soundon_check_status', ['queued', 'checking'])->update(['soundon_check_status' => 'stopped', 'soundon_check_error' => 'Dihentikan untuk update sistem.', 'soundon_check_finished_at' => now()]);
            ReleaseJob::whereIn('soundfresh_verify_status', ['sending', 'rejecting'])->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Dihentikan untuk update sistem.']);
            PublishRelease::whereIn('status', ['queued', 'publishing', 'updating_cms'])->update(['status' => 'failed', 'error' => 'Dihentikan untuk update sistem. Periksa hasil di Aggregator sebelum melanjutkan.', 'progress_message' => 'Dihentikan untuk update']);
            DB::table('jobs')->whereIn('queue', ['release-automation', 'status-checks', 'cms-verification', 'default'])->delete();
        });
    }
}
