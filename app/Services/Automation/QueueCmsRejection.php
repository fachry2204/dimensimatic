<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Jobs\RejectSoundfreshRelease;
use App\Models\ReleaseJob;
use Illuminate\Support\Facades\DB;

final class QueueCmsRejection
{
    public function dispatch(ReleaseJob $release, bool $retryFailed = false): bool
    {
        return DB::transaction(function () use ($release, $retryFailed): bool {
            $job = ReleaseJob::query()->lockForUpdate()->find($release->id);
            if (! $job || $job->soundon_check_status !== 'detected'
                || $job->soundon_release_status !== 'not_approved'
                || $job->soundfresh_workflow_status !== 'uploading'
                || ($job->soundfresh_verify_status !== null
                    && ! ($retryFailed && $job->soundfresh_verify_status === 'failed'))) {
                return false;
            }
            $reason = trim((string) $job->soundon_rejection_reason);
            if ($reason === '') {
                $job->update(['soundfresh_verify_status' => 'failed',
                    'soundfresh_verify_error' => 'Alasan penolakan SoundOn belum terbaca. Periksa ulang status atau isi alasan melalui tombol Reject.']);
                return false;
            }
            $job->update(['soundfresh_verify_status' => 'rejecting', 'soundfresh_verify_error' => null]);
            RejectSoundfreshRelease::dispatch($job->id, $reason)->beforeCommit();
            return true;
        }, 3);
    }

    public function dispatchDetected(): void
    {
        ReleaseJob::query()->whereNull('soundfresh_verify_status')
            ->where('soundfresh_workflow_status', 'uploading')
            ->where('soundon_check_status', 'detected')
            ->where('soundon_release_status', 'not_approved')
            ->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) $this->dispatch($job);
            });
    }
}
