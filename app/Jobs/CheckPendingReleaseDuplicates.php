<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Automation\UploadEligibleDraftsToSoundOn;
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

final class CheckPendingReleaseDuplicates implements ShouldQueue
{
    use Queueable;

    // Allow recovery from a stale database-queue reservation (for example
    // when the worker was restarted while SQLite was locked).
    public int $tries = 3;

    public int $timeout = 2700;

    /** @param array<int, string> $releaseJobIds */
    public function __construct(public readonly array $releaseJobIds, public readonly string $automationRunId)
    {
        $this->onQueue('release-automation');
    }

    public function handle(
        PlaywrightClient $worker,
        SessionManager $sessions,
        UploadEligibleDraftsToSoundOn $uploader,
    ): void
    {
        $jobs = ReleaseJob::query()
            ->whereIn('id', $this->releaseJobIds)
            ->where('error_code', 'DUPLICATE_CHECK_PENDING')
            ->get();
        if ($jobs->isEmpty()) {
            return;
        }

        $jobs->each->update(['progress_label' => 'Menyiapkan pemeriksaan duplikat', 'progress_percent' => 1]);

        $phase = 'validasi sesi platform';

        try {
            $soundfresh = $sessions->ensure(Platform::Soundfresh);
            $soundon = $sessions->ensure(Platform::SoundOn);
            if ($soundfresh->status === 'manual_auth_required' || $soundon->status === 'manual_auth_required') {
                throw new \RuntimeException(\App\Support\SystemBranding::text('Sesi Dimensi Suara atau SoundOn memerlukan login ulang.'));
            }

            $lookups = $jobs->map(fn (ReleaseJob $job): array => [
                'key' => (string) $job->id,
                'title' => (string) $job->release_title,
                'artist' => (string) $job->artist_name,
            ])->all();
            $jobs->each->update(['progress_percent' => 5, 'progress_label' => 'Mencari '.count($lookups).\App\Support\SystemBranding::text(' rilisan di Dimensi Suara All Releases...')]);
            $phase = \App\Support\SystemBranding::text('Dimensi Suara All Releases');
            $soundfreshMatches = $worker->soundfreshDuplicates($lookups, $soundfresh->session_state_encrypted);
            $jobs->each->update(['progress_percent' => 15, 'progress_label' => 'Memeriksa '.count($lookups).' rilisan di SoundOn All releases...']);
            $phase = 'SoundOn All Releases';
            $releaseMatches = $worker->releaseDuplicates($lookups, $soundon->session_state_encrypted);
            $jobs->each->update(['progress_percent' => 35, 'progress_label' => 'Memeriksa '.count($lookups).' rilisan di Drafts SoundOn...']);
            $phase = 'SoundOn Drafts';
            $draftMatches = $worker->findDrafts($lookups, $soundon->session_state_encrypted);
            $total = $jobs->count();
            foreach ($jobs as $index => $job) {
                $current = trim((string) $job->release_title);
                $percent = min(99, (int) round((($index + 1) / max(1, $total)) * 100));
                $job->update([
                    'progress_percent' => $percent,
                    'progress_label' => "Memeriksa duplikat: {$current} (".($index + 1)."/{$total})",
                ]);
                $sources = [];
                if (isset($soundfreshMatches[(string) $job->id])) {
                    $candidate = $soundfreshMatches[(string) $job->id];
                    if ((string) ($candidate['release_id'] ?? '') !== (string) $job->soundfresh_release_id) {
                        $tab = str_replace('_', ' ', (string) ($candidate['workflow_status'] ?? 'tab lain'));
                        $sources[] = \App\Support\SystemBranding::name()." tab {$tab} (ID ".($candidate['release_id'] ?? '?').')';
                    }
                }
                if (isset($releaseMatches[(string) $job->id])) {
                    $status = str_replace('_', ' ', (string) ($releaseMatches[(string) $job->id]['status'] ?? 'all releases'));
                    $sources[] = "SoundOn All releases (status {$status})";
                }
                $draftMatch = $draftMatches[(string) $job->id] ?? null;
                $sources = array_values(array_unique($sources));
                $submissionStarted = in_array($job->checkpoint, [
                    ReleaseCheckpoint::SoundOnDraftCreationStarted,
                    ReleaseCheckpoint::SoundOnDraftCreated,
                    ReleaseCheckpoint::MetadataFilled,
                    ReleaseCheckpoint::AssetsUploaded,
                    ReleaseCheckpoint::DraftSaved,
                    ReleaseCheckpoint::SoundfreshReviewed,
                    ReleaseCheckpoint::Completed,
                ], true);
                if ($draftMatch && $sources === [] && $submissionStarted) {
                    $draftId = (string) ($draftMatch['draft_id'] ?? '');
                    if ($draftId === '') {
                        throw new \RuntimeException('Draft SoundOn cocok tetapi Draft ID tidak tersedia.');
                    }
                    $job->update([
                        'status' => ReleaseJobStatus::Running,
                        'progress_label' => \App\Support\SystemBranding::text('Draft SoundOn sudah ada; memindahkan Dimensi Suara ke Under Review'),
                        'soundon_draft_id' => $draftId,
                        'soundon_draft_url' => $draftMatch['draft_url'] ?? null,
                        'soundon_draft_status' => 'already_exists',
                        'checkpoint' => ReleaseCheckpoint::DraftSaved,
                        'error_code' => null,
                        'error_message' => null,
                    ]);
                    $review = $worker->review(
                        (string) $job->id,
                        $job->soundfresh_release_url,
                        $draftId,
                        $soundfresh->session_state_encrypted,
                    );
                    if (($review['reviewed'] ?? false) !== true) {
                        throw new \RuntimeException(\App\Support\SystemBranding::text('Dimensi Suara tidak mengonfirmasi status Under Review.'));
                    }
                    $job->update([
                        'status' => ReleaseJobStatus::Completed,
                        'checkpoint' => ReleaseCheckpoint::Completed,
                        'soundfresh_workflow_status' => 'under_review',
                        'progress_label' => \App\Support\SystemBranding::text('Sudah ada di Draft SoundOn — Dimensi Suara dipindahkan ke Under Review'),
                        'progress_percent' => 100,
                        'finished_at' => now(),
                    ]);
                } elseif ($draftMatch || $sources !== []) {
                    if ($draftMatch) {
                        $sources[] = 'SoundOn Drafts';
                    }
                    $sources = array_values(array_unique($sources));
                    $job->update([
                        'status' => ReleaseJobStatus::NeedsAttention,
                        'progress_label' => 'Upload diblokir karena duplikat',
                        'error_code' => 'DUPLICATE_RELEASE_FOUND',
                        'error_message' => 'Tidak dapat di-upload otomatis: judul dan artis yang sama ditemukan di '.implode(', ', $sources).'. Metadata draft belum dikonfirmasi cocok dengan seluruh metadata sumber; cocokkan secara manual sebelum melanjutkan.',
                        'progress_percent' => 100,
                        'finished_at' => now(),
                    ]);
                } else {
                    $job->update([
                        'error_code' => null,
                        'error_message' => null,
                        'progress_percent' => 100,
                        'progress_label' => 'Pemeriksaan duplikat selesai — upload otomatis diantrikan',
                    ]);
                }
            }

            $this->updateSummary();

            $uploadIds = $jobs
                ->filter(fn (ReleaseJob $job): bool => $job->fresh()->error_code === null)
                ->pluck('id')
                ->map('strval')
                ->all();
            if ($uploadIds !== []) {
                $uploader->handle($uploadIds);
            }
        } catch (Throwable $exception) {
            ReleaseJob::query()
                ->whereIn('id', $jobs->pluck('id'))
                ->where('error_code', 'DUPLICATE_CHECK_PENDING')
                ->update([
                    'status' => ReleaseJobStatus::NeedsAttention,
                    'progress_label' => 'Pemeriksaan duplikat gagal',
                    'error_code' => 'DUPLICATE_CHECK_FAILED',
                    'error_message' => "Upload dinonaktifkan karena pemeriksaan duplikat gagal pada {$phase}: ".$exception->getMessage(),
                    'finished_at' => now(),
                ]);
            $run = AutomationRun::query()->find($this->automationRunId);
            $run?->update([
                'status' => \App\Enums\AutomationRunStatus::Completed,
                'finished_at' => now(),
                'summary_json' => [...($run->summary_json ?? []), 'duplicate_check_completed' => false, 'duplicate_check_failed' => true],
            ]);
            report($exception);
        }
    }

    private function updateSummary(): void
    {
        $run = AutomationRun::query()->find($this->automationRunId);
        if (! $run) {
            return;
        }
        $totalBlocked = $run->releaseJobs()->where('error_code', 'DUPLICATE_RELEASE_FOUND')->count();
        $run->update([
            'status' => \App\Enums\AutomationRunStatus::Completed,
            'finished_at' => now(),
            'skipped' => $totalBlocked,
            'summary_json' => [...($run->summary_json ?? []), 'duplicate_blocked' => $totalBlocked, 'duplicate_check_completed' => true],
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        ReleaseJob::query()->whereIn('id', $this->releaseJobIds)
            ->where('error_code', 'DUPLICATE_CHECK_PENDING')->update([
                'status' => ReleaseJobStatus::NeedsAttention,
                'error_code' => 'DUPLICATE_CHECK_FAILED',
                'error_message' => 'Worker pemeriksaan berhenti atau melewati batas waktu. Jalankan pemeriksaan ulang. '.($exception?->getMessage() ?? ''),
                'progress_label' => 'Pemeriksaan duplikat terhenti — perlu dicoba ulang',
                'finished_at' => now(),
            ]);
        $run = AutomationRun::query()->find($this->automationRunId);
        $run?->update([
            'status' => \App\Enums\AutomationRunStatus::Completed,
            'finished_at' => now(),
            'summary_json' => [...($run->summary_json ?? []), 'duplicate_check_completed' => false, 'duplicate_check_failed' => true],
        ]);
    }

}
