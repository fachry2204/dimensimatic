<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AutomationRunStatus;
use App\Enums\Platform;
use App\Enums\ReleaseCheckpoint;
use App\Enums\ReleaseJobStatus;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class QueueSoundOnStatusChecks implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly ?int $userId = null,
        public readonly string $sourceTab = 'uploading',
        public readonly ?string $statusRunId = null,
    )
    {
        $this->onQueue('status-checks');
    }

    public function handle(PlaywrightClient $worker, SessionManager $sessions): void
    {
        $run = $this->statusRunId
            ? AutomationRun::query()->find($this->statusRunId)
            : null;
        // A removed snapshot must never be resurrected by an old queue message.
        if ($this->statusRunId && (! $run || ! in_array($run->status->value, ['queued', 'running'], true))) return;
        if ($run) {
            $run->update([
                'status' => AutomationRunStatus::Running,
                'started_at' => now(),
                'finished_at' => null,
                'summary_json' => ['purpose' => 'soundon_status_check', 'soundfresh_source' => 'uploading', 'phase' => 'collecting'],
            ]);
        } else {
            $run = AutomationRun::query()->create([
                'triggered_by' => $this->userId,
                'status' => AutomationRunStatus::Running,
                'started_at' => now(),
                'summary_json' => ['purpose' => 'soundon_status_check', 'soundfresh_source' => 'uploading', 'phase' => 'collecting'],
            ]);
        }

        if ($this->job?->getJobId()) {
            $run->update(['summary_json' => [...($run->summary_json ?? []), 'dispatcher_job_id' => (string) $this->job->getJobId()]]);
        }

        try {
            $soundfresh = $sessions->ensure(Platform::Soundfresh);
            if ($soundfresh->status === 'manual_auth_required') {
                $run->update(['status' => AutomationRunStatus::ManualAuthRequired, 'finished_at' => now()]);

                return;
            }

            // Bulk collection always reads every Uploading release, including
            // jobs serialized before the source selector was removed.
            $canonicalItems = collect($worker->uploading(null, $soundfresh->session_state_encrypted))
                ->keyBy(fn (array $item): string => (string) $item['release_id'])
                ->map(fn (array $item): array => [...$item, 'workflow_status' => 'uploading'])
                ->values();
            if (! AutomationRun::query()->whereKey($run->id)->where('status', 'running')->exists()) return;
            $run->update(['pending_found' => $canonicalItems->count()]);

            // Refresh the actual tab membership for locally known releases.
            $canonicalItems->pluck('release_id')->filter()->chunk(500)->each(function ($ids) use ($canonicalItems): void {
                $known = ReleaseJob::query()->whereIn('soundfresh_release_id', $ids->all())->get()->keyBy('soundfresh_release_id');
                foreach ($canonicalItems->whereIn('release_id', $ids->all()) as $item) {
                    $job = $known->get((string) $item['release_id']);
                    if ($job) {
                        $snapshot = $job->metadata_snapshot_json ?? [];
                        if (filled($item['release_date'] ?? null)) {
                            $snapshot['release_date'] = $item['release_date'];
                        }
                        $job->update([
                            'soundfresh_workflow_status' => (string) ($item['workflow_status'] ?? 'unknown'),
                            'metadata_snapshot_json' => $snapshot !== [] ? $snapshot : $job->metadata_snapshot_json,
                        ]);
                    }
                }
            });

            // This action is a full refresh of the Dimensi Suara Uploading tab.
            // Recheck previously known releases too; filtering those out made
            // later scans enqueue only newly discovered items, so the table
            // could never reflect every release currently in Dimensi Suara.
            $actionableItems = $canonicalItems;
            $store = function (array $item, string $sourceStatus) use ($run): ReleaseJob {
                $key = 'soundfresh:'.$item['release_id'].':soundon';
                $job = ReleaseJob::query()->where('idempotency_key', $key)->first();
                $snapshot = $job?->metadata_snapshot_json ?? [];
                if (filled($item['release_date'] ?? null)) {
                    $snapshot['release_date'] = $item['release_date'];
                }
                if ($job) {
                    $job->update([
                        'automation_run_id' => $run->id,
                        'soundfresh_release_url' => $item['detail_url'],
                        'release_title' => $item['title'] ?? $job->release_title,
                        'artist_name' => filled($item['primary_artist'] ?? null) ? $item['primary_artist'] : $job->artist_name,
                        'track_count' => max(1, (int) ($item['track_count'] ?? $job->track_count)),
                        'soundfresh_workflow_status' => $sourceStatus,
                        'metadata_snapshot_json' => $snapshot !== [] ? $snapshot : $job->metadata_snapshot_json,
                    ]);

                    return $job->fresh();
                }

                return ReleaseJob::query()->create([
                    'automation_run_id' => $run->id,
                    'soundfresh_release_id' => $item['release_id'],
                    'soundfresh_release_url' => $item['detail_url'],
                    'idempotency_key' => $key,
                    'release_title' => $item['title'] ?? null,
                    'artist_name' => $item['primary_artist'] ?? null,
                    'track_count' => max(1, (int) ($item['track_count'] ?? 1)),
                    'soundfresh_workflow_status' => $sourceStatus,
                    'metadata_snapshot_json' => $snapshot !== [] ? $snapshot : null,
                    'status' => ReleaseJobStatus::Completed,
                    'checkpoint' => ReleaseCheckpoint::Discovered,
                    'finished_at' => now(),
                ]);
            };
            $underReviewJobs = $actionableItems->where('workflow_status', 'under_review')->map(fn (array $item): ReleaseJob => $store($item, 'under_review'))
                ->filter(fn (ReleaseJob $job): bool => filled($job->release_title))->values();
            $uploadingJobs = $actionableItems->where('workflow_status', 'uploading')->map(fn (array $item): ReleaseJob => $store($item, 'uploading'))
                ->filter(fn (ReleaseJob $job): bool => filled($job->release_title))->values();
            $jobs = $underReviewJobs->concat($uploadingJobs)->unique('id')->values();

            foreach ($jobs as $job) {
                // Preserve the last detected status and identifiers while the
                // replacement scan runs. This keeps reports populated and the
                // UI stable until the new SoundOn result is committed.
                $job->update([
                    'soundon_check_status' => 'queued',
                    'soundon_check_progress' => 5,
                    'soundon_check_error' => null,
                    'soundon_check_started_at' => null,
                    'soundon_check_finished_at' => null,
                ]);
            }
            $run->update(['summary_json' => [...($run->summary_json ?? []), 'phase' => 'checking']]);
            $underReviewJobs->pluck('id')->chunk(5)->each(
                fn ($ids) => CheckSoundOnReleaseStatus::dispatch($ids->values()->all(), $run->id, 'under_review')
            );
            $uploadingJobs->pluck('id')->chunk(5)->each(
                fn ($ids) => CheckSoundOnReleaseStatus::dispatch($ids->values()->all(), $run->id, 'uploading')
            );
            if ($jobs->isEmpty()) {
                $run->update(['status' => AutomationRunStatus::Completed, 'finished_at' => now()]);
            }
        } catch (Throwable $exception) {
            $run->update([
                'status' => AutomationRunStatus::Failed,
                'finished_at' => now(),
                'summary_json' => [...($run->summary_json ?? []), 'error' => $exception->getMessage()],
            ]);
            report($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        if (! $this->statusRunId) return;
        $run = AutomationRun::query()->whereKey($this->statusRunId)->whereIn('status', ['queued', 'running'])->first();
        if ($run) $run->update(['status' => AutomationRunStatus::Failed, 'finished_at' => now(),
            'summary_json' => [...($run->summary_json ?? []), 'error' => $exception?->getMessage() ?? 'Worker pengambilan rilisan berhenti.']]);
    }
}
