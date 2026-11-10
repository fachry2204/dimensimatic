<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Jobs\VerifySoundfreshReleaseIdentifiers;
use App\Models\ReleaseJob;
use Illuminate\Support\Facades\DB;

final class QueueCmsVerification
{
    public function dispatch(ReleaseJob $release, bool $retryFailed = false): bool
    {
        return DB::transaction(function () use ($release, $retryFailed): bool {
            $job = ReleaseJob::query()->lockForUpdate()->find($release->id);
            if (! $job || $job->cmsVerificationIdentifiers() === null
                || ($job->soundfresh_verify_status !== null
                    && ! ($retryFailed && $job->soundfresh_verify_status === 'failed'))) {
                return false;
            }
            $job->update(['soundfresh_verify_status' => 'sending', 'soundfresh_verify_error' => null]);
            // Insert the durable database job in the same transaction as the claim.
            VerifySoundfreshReleaseIdentifiers::dispatch($job->id)->beforeCommit();
            return true;
        }, 3);
    }

    public function dispatchDetected(): void
    {
        ReleaseJob::query()->whereNull('soundfresh_verify_status')
            ->where('soundfresh_workflow_status', 'uploading')
            ->where('soundon_check_status', 'detected')
            ->whereIn('soundon_release_status', ['approved', 'delivery', 'live'])
            ->whereNotNull('soundon_upc')->chunkById(100, function ($jobs): void {
                foreach ($jobs as $job) {
                    $this->dispatch($job);
                }
            });
    }
}
