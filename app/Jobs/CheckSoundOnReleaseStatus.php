<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Platform;
use App\Enums\AutomationRunStatus;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use App\Services\Automation\AttentionNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class CheckSoundOnReleaseStatus implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public readonly array $releaseJobIds;

    public readonly ?string $releaseJobId;

    public function __construct(string|array $releaseJobIds, public readonly ?string $statusRunId = null, public readonly string $sourceStatus = 'uploading')
    {
        $this->releaseJobIds = array_values((array) $releaseJobIds);
        $this->releaseJobId = is_string($releaseJobIds) ? $releaseJobIds : null;
        $this->onQueue('status-checks');
    }

    public function handle(PlaywrightClient $worker, SessionManager $sessions, AttentionNotifier $notifier): void
    {
        if ($this->statusRunId && ! AutomationRun::query()->whereKey($this->statusRunId)->whereIn('status', ['queued', 'running'])->exists()) return;
        if (! in_array($this->sourceStatus, ['under_review', 'uploading'], true)) {
            ReleaseJob::query()->whereIn('id', $this->releaseJobIds)->update([
                'soundon_check_status' => null,
                'soundon_check_progress' => 0,
                'soundon_check_error' => null,
                'soundon_check_started_at' => null,
                'soundon_check_finished_at' => null,
            ]);
            $this->finishStatusRunWhenDrained();

            return;
        }
        $isPreUploadCheck = $this->sourceStatus === 'under_review';
        $jobs = ReleaseJob::query()->whereIn('id', $this->releaseJobIds)->get();
        if ($jobs->isEmpty()) {
            return;
        }
        ReleaseJob::query()->whereIn('id', $jobs->pluck('id'))
            ->where('soundon_check_status', '!=', 'detected')
            ->update([
            'soundon_check_status' => 'checking',
            'soundon_check_progress' => 20,
            'soundon_check_error' => null,
            'soundon_check_started_at' => now(),
            'soundon_check_finished_at' => null,
        ]);

        try {
            $soundOn = $sessions->ensure(Platform::SoundOn);
            $matches = $worker->releaseStatuses($jobs->map(fn (ReleaseJob $job): array => [
                'key' => (string) $job->id,
                'title' => (string) $job->release_title,
                'artist' => (string) ($job->artist_name ?? ''),
            ])->all(), $soundOn->session_state_encrypted);

            $soundfresh = $isPreUploadCheck ? $sessions->ensure(Platform::Soundfresh) : null;

            foreach ($jobs as $job) {
                if ($this->statusRunId && ! AutomationRun::query()->whereKey($this->statusRunId)->whereIn('status', ['queued', 'running'])->exists()) return;
                $match = $matches[$job->id] ?? null;
                if (! is_array($match)) {
                    $message = 'Data lagu tidak ada di All Releases SoundOn: '.$job->release_title.($job->artist_name ? ' — '.$job->artist_name : '').'.';
                    $job->update(['soundon_check_status' => 'not_found', 'soundon_check_progress' => 100,
                        'soundon_check_error' => $message, 'soundon_status_checked_at' => now(), 'soundon_check_finished_at' => now()]);
                    if ($isPreUploadCheck) {
                        $notifier->send('soundon_release_not_found', $message, $job->automation_run_id, $job->id, 'SOUNDON_RELEASE_NOT_FOUND');
                    }
                    continue;
                }
                $rawStatus = trim((string) ($match['status'] ?? ''));
                $status = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $rawStatus));
                $status = trim($status, '_');
                $status = match ($status) {
                    'underreview', 'in_review', 'reviewing' => 'under_review',
                    'delivered' => 'delivery',
                    'aproved' => 'approved',
                    'notapproved', 'reject', 'rejected', 'declined', 'ditolak' => 'not_approved',
                    default => $status,
                };
                if (! in_array($status, ['under_review', 'delivery', 'approved', 'not_approved', 'live'], true)) {
                    $job->update(['soundon_check_status' => 'failed', 'soundon_check_progress' => 100,
                        'soundon_check_error' => 'Status SoundOn tidak dikenali: '.($rawStatus !== '' ? $rawStatus : '(kosong)').'.', 'soundon_status_checked_at' => now(),
                        'soundon_check_finished_at' => now()]);
                    continue;
                }
                if ($isPreUploadCheck) {
                    $worker->moveSoundfreshToUploading($job->soundfresh_release_url, $soundfresh?->session_state_encrypted);
                    $job->update([
                        'soundfresh_workflow_status' => 'uploading',
                        'soundon_release_status' => $status,
                        'soundon_rejection_reason' => $status === 'not_approved' ? trim((string) ($match['reason'] ?? '')) ?: null : null,
                        'soundon_draft_url' => $match['release_url'] ?? $job->soundon_draft_url,
                        'soundon_upc' => null,
                        'soundon_isrcs_json' => null,
                        'soundon_status_checked_at' => now(),
                        'soundon_check_status' => 'queued',
                        'soundon_check_progress' => 5,
                        'soundon_check_error' => null,
                        'soundon_check_started_at' => null,
                        'soundon_check_finished_at' => null,
                    ]);
                    self::dispatch($job->id, $this->statusRunId, 'uploading');
                    continue;
                }
                $upc = in_array($status, ['delivery', 'approved', 'live'], true) ? trim((string) ($match['upc'] ?? '')) : '';
                $isrcs = in_array($status, ['delivery', 'approved', 'live'], true)
                    ? array_values(array_filter((array) ($match['isrcs'] ?? [])))
                    : [];
                $job->update([
                    'soundfresh_workflow_status' => 'uploading',
                    'soundon_release_status' => $status, 'soundon_draft_url' => $match['release_url'] ?? $job->soundon_draft_url,
                    'soundon_rejection_reason' => $status === 'not_approved' ? trim((string) ($match['reason'] ?? '')) ?: null : null,
                    'soundon_upc' => $upc !== '' ? $upc : null,
                    'soundon_isrcs_json' => $isrcs !== [] ? $isrcs : null,
                    'soundon_status_checked_at' => now(), 'soundon_check_status' => 'detected',
                    'soundon_check_progress' => 100, 'soundon_check_finished_at' => now(),
                ]);
                app(\App\Services\Automation\QueueCmsVerification::class)->dispatch($job, true);
                app(\App\Services\Automation\QueueCmsRejection::class)->dispatch($job, true);
            }
        } catch (Throwable $exception) {
            ReleaseJob::query()->whereIn('id', $jobs->pluck('id'))->update([
                'soundon_check_status' => 'failed',
                'soundon_check_progress' => 100,
                'soundon_check_error' => $exception->getMessage(),
                'soundon_status_checked_at' => now(),
                'soundon_check_finished_at' => now(),
            ]);
            report($exception);
        } finally {
            $this->finishStatusRunWhenDrained();
        }
    }

    public function failed(?Throwable $exception): void
    {
        ReleaseJob::query()->whereIn('id', $this->releaseJobIds)
            ->whereIn('soundon_check_status', ['queued', 'checking'])
            ->update([
                'soundon_check_status' => 'failed',
                'soundon_check_progress' => 100,
                'soundon_check_error' => $exception?->getMessage() ?? 'Worker pemeriksaan SoundOn berhenti sebelum proses selesai.',
                'soundon_status_checked_at' => now(),
                'soundon_check_finished_at' => now(),
            ]);

        $this->finishStatusRunWhenDrained();
    }

    private function finishStatusRunWhenDrained(): void
    {
        if (! $this->statusRunId) {
            return;
        }

        $remaining = ReleaseJob::query()
            ->where('automation_run_id', $this->statusRunId)
            ->whereIn('soundon_check_status', ['queued', 'checking'])
            ->exists();
        if ($remaining) {
            return;
        }

        $run = AutomationRun::query()->find($this->statusRunId);
        if (! $run || ! in_array($run->status->value, ['queued', 'running'], true)) {
            return;
        }

        $detected = ReleaseJob::query()->where('automation_run_id', $run->id)->where('soundon_check_status', 'detected')->count();
        $notDetected = ReleaseJob::query()->where('automation_run_id', $run->id)->whereIn('soundon_check_status', ['failed', 'not_found'])->count();
        $run->update([
            'status' => AutomationRunStatus::Completed,
            'summary_json' => [...($run->summary_json ?? []), 'detected' => $detected, 'not_detected' => $notDetected],
            'finished_at' => now(),
        ]);
    }
}
