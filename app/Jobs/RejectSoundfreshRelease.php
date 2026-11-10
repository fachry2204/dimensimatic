<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class RejectSoundfreshRelease implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $releaseJobId, public readonly string $reason)
    {
        $this->onQueue('cms-verification');
    }

    public function handle(PlaywrightClient $worker, SessionManager $sessions): void
    {
        $job = ReleaseJob::query()->find($this->releaseJobId);
        if (! $job || $job->soundfresh_verify_status !== 'rejecting') return;
        if ($job->soundon_release_status !== 'not_approved' || $job->soundfresh_workflow_status !== 'uploading') {
            $job->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => 'Reject hanya tersedia untuk rilisan Uploading berstatus Not Approved.']);
            return;
        }

        try {
            if (trim($this->reason) === '') throw new RuntimeException('Alasan penolakan SoundOn wajib diisi.');
            $soundfresh = $sessions->ensure(Platform::Soundfresh);
            if ($soundfresh->status === 'manual_auth_required') {
                throw new RuntimeException(\App\Support\SystemBranding::text('Sesi Dimensi Suara perlu login ulang dari Pengaturan Platform.'));
            }
            $result = $worker->rejectSoundfreshRelease($job->soundfresh_release_url, $this->reason, $soundfresh->session_state_encrypted);
            if (($result['rejected'] ?? false) !== true) {
                throw new RuntimeException(\App\Support\SystemBranding::text('Dimensi Suara tidak mengonfirmasi penolakan rilisan.'));
            }
            $job->refresh();
            if ($job->soundfresh_verify_status !== 'rejecting') {
                return;
            }
            $job->update([
                'soundfresh_workflow_status' => 'rejected',
                'soundfresh_verify_status' => 'rejected',
                'soundfresh_verify_error' => null,
                'soundfresh_rejected_at' => now(),
            ]);
        } catch (Throwable $exception) {
            report($exception);
            $job->refresh();
            if ($job->soundfresh_verify_status !== 'rejecting') {
                return;
            }
            $job->update(['soundfresh_verify_status' => 'failed', 'soundfresh_verify_error' => $exception->getMessage()]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReleaseJob::query()->whereKey($this->releaseJobId)
            ->where('soundfresh_verify_status', 'rejecting')->update([
                'soundfresh_verify_status' => 'failed',
                'soundfresh_verify_error' => 'Reject CMS terputus: '.($exception?->getMessage() ?? 'Worker berhenti sebelum selesai.'),
            ]);
    }
}
