<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\Platform;
use App\Enums\ReleaseCheckpoint;
use App\Enums\ReleaseJobStatus;
use App\Jobs\CheckPendingReleaseDuplicates;
use App\Jobs\CollectPendingSoundfreshReleases;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\PlaywrightClient;
use App\Services\Automation\SessionManager;
use Throwable;

final class StartAutomationRun
{
    public function __construct(
        private readonly PlaywrightClient $worker,
        private readonly SessionManager $sessions,
        private readonly UploadEligibleDraftsToSoundOn $uploader,
    ) {}

    public function handle(?int $userId = null, ?int $limit = null): AutomationRun
    {
        $run = AutomationRun::create([
            'triggered_by' => $userId,
            'status' => AutomationRunStatus::Queued,
            'started_at' => now(),
            'summary_json' => ['draft_only' => true, 'automatic_upload' => true, 'awaiting_selection' => false, 'collection_pending' => true],
        ]);

        // Never call a browser worker during the Livewire request. Browser
        // navigation can take minutes and used to lock every page in the app.
        // Tests intentionally execute the collector inline so their existing
        // synchronous assertions keep describing the collected monitor state.
        if (app()->environment('testing')) {
            $this->collect($run, $limit);
        } else {
            CollectPendingSoundfreshReleases::dispatch((string) $run->id, $limit);
        }

        return $run;
    }

    public function collect(AutomationRun $run, ?int $limit = null): void
    {
        try {
            $account = $this->sessions->ensure(Platform::Soundfresh);
            if ($account->status === 'manual_auth_required') {
                $run->update(['status' => AutomationRunStatus::ManualAuthRequired, 'finished_at' => now()]);

                return;
            }
            $items = $this->worker->pending($limit, $account->session_state_encrypted, true);
        } catch (Throwable $exception) {
            $run->update([
                'status' => AutomationRunStatus::Failed,
                'finished_at' => now(),
                'summary_json' => [
                    ...($run->summary_json ?? []),
                    'collection_pending' => false,
                    'error' => $exception->getMessage(),
                ],
            ]);

            report($exception);

            return;
        }

        // A Pause/Stop can be requested while the browser is reading many
        // Dimensi Suara pages. Do not recreate monitor rows after that signal.
        $run->refresh();
        if ($run->stop_requested_at || ! in_array($run->status, [AutomationRunStatus::Queued, AutomationRunStatus::Running], true)) {
            return;
        }

        if ($items === []) {
            $run->update([
                'status' => AutomationRunStatus::CompletedNoPending,
                'finished_at' => now(),
                'summary_json' => [...($run->summary_json ?? []), 'collection_pending' => false],
            ]);

            return;
        }

        // Duplicate detection is the first gate after collecting Pending. A
        // release may only become selectable after title + primary artist have
        // been checked against every Dimensi Suara release, SoundOn All releases,
        // and SoundOn Drafts.
        $releaseIds = collect($items)->pluck('release_id')->map('strval')->all();
        $draftMatches = ReleaseJob::query()
            ->whereIn('soundfresh_release_id', $releaseIds ?: [''])
            ->whereNotNull('soundon_draft_id')
            ->get()
            ->keyBy(fn (ReleaseJob $job): string => (string) $job->soundfresh_release_id)
            ->all();

        $blockedCount = 0;
        $duplicateCheckIds = [];
        $run->update([
            'pending_found' => count($items),
            'summary_json' => [
                ...($run->summary_json ?? []),
                // The monitor can render its rows as soon as they have been
                // stored locally. Remote duplicate checks run separately and
                // must never keep the release table behind a loading state.
                'collection_pending' => false,
                'pending_release_ids' => array_values(array_map(
                    static fn (array $item): string => (string) $item['release_id'],
                    $items,
                )),
            ],
        ]);

        foreach ($items as $item) {
            $key = 'soundfresh:'.$item['release_id'].':soundon';

            if (ReleaseJob::where('idempotency_key', $key)->exists()) {
                $run->increment('skipped');

                continue;
            }

            $isBlocked = isset($draftMatches[(string) $item['release_id']]);
            if ($isBlocked) {
                $blockedCount++;
            }

            $canCheck = $this->duplicateKey($item) !== null && ! $isBlocked;
            $job = ReleaseJob::create([
                'idempotency_key' => $key,
                'automation_run_id' => $run->id,
                'soundfresh_release_id' => $item['release_id'],
                'soundfresh_release_url' => $item['detail_url'],
                'soundfresh_workflow_status' => 'pending',
                'release_title' => $item['title'] ?? null,
                'artist_name' => $item['primary_artist'] ?? null,
                'track_count' => max(1, (int) ($item['track_count'] ?? 1)),
                'status' => $isBlocked ? ReleaseJobStatus::NeedsAttention : ReleaseJobStatus::Queued,
                'checkpoint' => ReleaseCheckpoint::Discovered,
                'progress_percent' => 5,
                'progress_label' => $isBlocked ? 'Upload diblokir karena duplikat' : ($canCheck ? 'Menunggu pemeriksaan duplikat' : 'Siap dipilih untuk upload'),
                'error_code' => $isBlocked ? 'DUPLICATE_RELEASE_FOUND' : ($canCheck ? 'DUPLICATE_CHECK_PENDING' : null),
                'error_message' => $isBlocked
                    ? 'Tidak dapat di-upload: judul dan artis yang sama ditemukan di SoundOn Drafts.'
                    : null,
                'finished_at' => $isBlocked ? now() : null,
            ]);
            if ($canCheck) {
                $duplicateCheckIds[] = (string) $job->id;
            }
        }

        $run->update([
            'skipped' => $blockedCount,
            'summary_json' => [
                ...($run->fresh()->summary_json ?? []),
                'duplicate_blocked' => $blockedCount,
            ],
        ]);

        if ($duplicateCheckIds !== []) {
            CheckPendingReleaseDuplicates::dispatch($duplicateCheckIds, $run->id);
        }

        if ($run->releaseJobs()->doesntExist()) {
            $run->update(['status' => AutomationRunStatus::Completed, 'finished_at' => now()]);
        } elseif ($duplicateCheckIds === []) {
            $run->update([
                'status' => AutomationRunStatus::Completed,
                'finished_at' => now(),
                'summary_json' => [...($run->fresh()->summary_json ?? []), 'duplicate_check_completed' => true],
            ]);

            $uploadIds = $run->releaseJobs()
                ->whereNull('error_code')
                ->whereNull('soundon_draft_id')
                ->pluck('id')
                ->map(fn ($id): string => (string) $id)
                ->all();
            if ($uploadIds !== []) {
                $this->uploader->handle($uploadIds);
            }
        }
    }

    private function duplicateKey(array $item): ?string
    {
        $title = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($item['title'] ?? ''))) ?? '');
        $artist = mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) ($item['primary_artist'] ?? ''))) ?? '');

        return $title !== '' && $artist !== '' ? $title."\0".$artist : null;
    }
}
