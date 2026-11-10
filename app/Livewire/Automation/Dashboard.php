<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use App\Actions\Automation\PauseAutomationRun;
use App\Actions\Automation\ClearAutomationMonitor;
use App\Actions\Automation\ResumeAutomationRun;
use App\Actions\Automation\StartAutomationRun;
use App\Actions\Automation\StopAutomationRun;
use App\Actions\Automation\UploadEligibleDraftsToSoundOn;
use App\Enums\Platform;
use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Exceptions\CredentialsNotConfiguredException;
use App\Jobs\ProcessReleaseJob;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\OperatorAudit;
use App\Services\Automation\LocalSystemManager;
use App\Services\Automation\SessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

final class Dashboard extends Component
{
    /** @var array<int, string> */
    public array $selectedReleaseJobs = [];

    public ?string $monitorRunId = null;

    public string $releaseTab = 'all';

    public ?string $configurationError = null;

    public ?string $uploadMessage = null;

    public ?string $operationError = null;

    public bool $showWorkerOperationModal = false;

    public string $workerOperationTitle = '';

    public string $workerOperationMessage = '';

    public function refreshMonitor(): void
    {
        // The collection runs in a queue, outside this Livewire request.
        // Re-render from the database even when the tab was in the background.
        if ($this->monitorRunId && ! $this->visibleRunsQuery()->whereKey($this->monitorRunId)->exists()) {
            $this->monitorRunId = null;
        }
    }

    public function start(StartAutomationRun $start, ClearAutomationMonitor $clear, LocalSystemManager $system, SessionManager $sessions): void
    {
        // Reading every Dimensi Suara Pending page can exceed PHP's desktop
        // default of 60 seconds. Keep the Livewire request alive until the
        // browser worker returns or its own explicit timeout is reached.
        @set_time_limit(900);
        ini_set('max_execution_time', '900');
        Gate::authorize('automation.run');
        $this->configurationError = null;
        $this->operationError = null;
        $this->uploadMessage = null;
        $this->monitorRunId = null;
        $this->selectedReleaseJobs = [];
        if (! AutomationAccount::query()->where('platform', Platform::Soundfresh)->exists()) {
            $this->configurationError = \App\Support\SystemBranding::text('Credential Dimensi Suara belum dikonfigurasi pada halaman Pengaturan.');

            return;
        }
        $stage = \App\Support\SystemBranding::text('memeriksa credential Dimensi Suara');
        $diagnosticId = (string) \Illuminate\Support\Str::uuid();
        try {
            // Fail before clearing existing monitor data or restarting workers.
            $sessions->assertReadable(Platform::Soundfresh);
            // Treat "Ambil semua" as an atomic cold restart. The lock rejects
            // double-clicks/concurrent tabs, while stopping before clearing
            // prevents an old consumer from reserving jobs during the reset.
            $run = Cache::lock('automation:pending-collection-reset', 120)->block(20, function () use ($system, $clear, $start, &$stage): AutomationRun {
                $stage = 'menghentikan antrean pengambilan lama';
                $system->stopQueueWorkers(['release-automation']);
                $stage = 'membersihkan monitor pengambilan';
                $clear->handle();

                // Clear a reservation inserted between cancellation and the
                // process shutdown. The new run is dispatched only after one
                // fresh browser worker and one worker per queue are online.
                DB::table('jobs')->where('queue', 'release-automation')->delete();
                $stage = 'menyalakan layanan browser dan antrean';
                $system->start();

                $stage = \App\Support\SystemBranding::text('mengantrikan pengambilan Dimensi Suara');
                return $start->handle(auth()->id());
            });
            $this->monitorRunId = $run->id;
            $this->selectedReleaseJobs = [];
        } catch (CredentialsNotConfiguredException $error) {
            $label = $error->platform === Platform::Soundfresh ? \App\Support\SystemBranding::text('Dimensi Suara') : 'SoundOn';
            $this->configurationError = $error->keyMismatch
                ? "Credential {$label} dari database lama tidak dapat dibaca. Masukkan ulang email dan password di Pengaturan Platform."
                : "Credential {$label} belum dikonfigurasi pada halaman Pengaturan.";

            return;
        } catch (Throwable $error) {
            report($error);
            logger()->error('pending_collection_start_failed', [
                'diagnostic_id' => $diagnosticId, 'stage' => $stage,
                'exception_class' => $error::class,
            ]);
            $this->operationError = 'Pengambilan gagal saat '.$stage.'. Kode pemeriksaan: '.$diagnosticId.'. Periksa storage/logs/laravel.log dengan kode tersebut.';

            return;
        }
        $this->dispatch('run-started');
    }

    public function pause(string $id, PauseAutomationRun $action, LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        $action->handle(AutomationRun::findOrFail($id));
        $system->stopAllWorkers();
        $this->uploadMessage = 'Run dan seluruh worker dijeda. Tekan Ambil semua rilisan pending atau Continue untuk menyalakannya kembali.';
    }

    public function uploadToSoundOn(UploadEligibleDraftsToSoundOn $action): void
    {
        Gate::authorize('automation.run');
        $this->operationError = null;
        $selected = array_values(array_unique(array_map('strval', $this->selectedReleaseJobs)));
        if ($selected === []) {
            $this->operationError = 'Pilih minimal satu rilisan yang akan di-upload ke SoundOn.';

            return;
        }
        try {
            $result = $action->handle($selected);
        } catch (Throwable $error) {
            report($error);
            $this->operationError = 'Upload ke SoundOn gagal diantrikan. Periksa sesi SoundOn dan status automation worker.';

            return;
        }
        $this->selectedReleaseJobs = [];
        $this->uploadMessage = "{$result['queued']} rilisan terpilih diantrikan ke SoundOn; {$result['skipped']} pilihan yang sudah diproses atau tidak eligible dilewati.";
        $this->dispatch('soundon-upload-started');
    }

    public function setReleaseTab(string $tab): void
    {
        $this->releaseTab = in_array($tab, ['all', 'uploaded', 'failed'], true) ? $tab : 'all';
        $this->selectedReleaseJobs = [];
    }

    public function toggleSelectAll(): void
    {
        $selectableIds = $this->selectableReleaseJobIds();
        $selected = array_values(array_intersect($this->selectedReleaseJobs, $selectableIds));
        $this->selectedReleaseJobs = count($selected) === count($selectableIds) ? [] : $selectableIds;
    }

    public function resume(string $id, ResumeAutomationRun $action, LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        $system->start();
        $action->handle(AutomationRun::findOrFail($id));
    }

    public function stop(string $id, StopAutomationRun $action): void
    {
        Gate::authorize('automation.run');
        $action->handle(AutomationRun::findOrFail($id));
    }

    public function stopAllWorkers(StopAutomationRun $stop, LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        if ($run = AutomationRun::query()->latest()->first()) {
            $stop->handle($run);
        }
        $systemResult = $system->stopAllWorkers();
        $this->selectedReleaseJobs = [];
        $this->uploadMessage = 'Semua worker (Browser, Queue, & Checker) dan antrean berhasil dihentikan. ('.$systemResult['message'].')';
        $this->showWorkerOperationSuccess('Semua worker dihentikan', $this->uploadMessage);
        $this->dispatch('workers-stopped');
    }

    public function stopWorkerProcess(LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        $result = $system->stop();
        $this->uploadMessage = $result['message'];
        $this->showWorkerOperationSuccess('Browser worker dihentikan', $result['message']);
    }

    public function startWorkerProcess(LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        try {
            $result = $system->start();
            $this->uploadMessage = $result['message'];
            $this->showWorkerOperationSuccess('Worker berhasil dinyalakan', $result['message']);
        } catch (Throwable $error) {
            $this->operationError = $error->getMessage();
        }
    }

    public function startQueueWorker(StartAutomationRun $start, ClearAutomationMonitor $clear, LocalSystemManager $system, SessionManager $sessions): void
    {
        Gate::authorize('automation.run');
        $this->operationError = null;
        $this->uploadMessage = null;

        $pausedRun = AutomationRun::query()->where('status', AutomationRunStatus::Paused)->latest()->first();
        if ($pausedRun) {
            $system->start();
            app(ResumeAutomationRun::class)->handle($pausedRun);
            $this->uploadMessage = 'Pipeline queue worker dinyalakan kembali (melanjutkan run '.$pausedRun->id.').';
            $this->showWorkerOperationSuccess('Pipeline queue dinyalakan', $this->uploadMessage);

            return;
        }

        $this->start($start, $clear, $system, $sessions);
        if ($this->operationError === null && $this->configurationError === null) {
            $this->showWorkerOperationSuccess('Pipeline queue dinyalakan', \App\Support\SystemBranding::text('Pipeline queue siap mengambil dan memproses rilisan dari Dimensi Suara.'));
        }
    }

    public function stopQueueWorker(StopAutomationRun $stop, LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        $this->operationError = null;
        $this->uploadMessage = null;

        DB::transaction(function (): void {
            $stoppedAt = now();
            AutomationRun::query()
                ->whereIn('status', [AutomationRunStatus::Running, AutomationRunStatus::Queued, AutomationRunStatus::Paused])
                ->where(function ($query): void {
                    $query->whereNull('summary_json->purpose')
                        ->orWhere('summary_json->purpose', '!=', 'soundon_status_check');
                })
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

            DB::table('jobs')->where('queue', 'release-automation')->delete();
        });

        $system->stopQueueWorkers(['release-automation']);

        $this->uploadMessage = 'Pipeline queue worker berhasil dihentikan.';
        $this->showWorkerOperationSuccess('Pipeline queue dihentikan', $this->uploadMessage);
    }

    public function startCheckWorker(LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        $this->operationError = null;
        $this->uploadMessage = null;

        try {
            $system->start();
        } catch (Throwable $error) {
            report($error);
            $this->operationError = 'Layanan pemeriksaan belum berhasil dinyalakan. Periksa log worker.';
            return;
        }

        $pendingDuplicateJobs = ReleaseJob::query()
            ->whereIn('error_code', ['DUPLICATE_CHECK_PENDING', 'DUPLICATE_CHECK_FAILED'])
            ->pluck('id')
            ->map('strval')
            ->all();

        if ($pendingDuplicateJobs !== []) {
            $latestRun = AutomationRun::query()->latest()->first();
            if ($latestRun) {
                ReleaseJob::query()
                    ->whereIn('id', $pendingDuplicateJobs)
                    ->update([
                        'status' => ReleaseJobStatus::Queued,
                        'error_code' => 'DUPLICATE_CHECK_PENDING',
                        'error_message' => null,
                        'progress_percent' => 5,
                        'progress_label' => 'Menyiapkan pemeriksaan duplikat',
                    ]);

                \App\Jobs\CheckPendingReleaseDuplicates::dispatch($pendingDuplicateJobs, (string) $latestRun->id);
                $this->uploadMessage = 'Pemeriksaan duplikat diantrikan ulang untuk '.count($pendingDuplicateJobs).' rilisan.';
                $this->showWorkerOperationSuccess('Verification & check worker dinyalakan', $this->uploadMessage);

                return;
            }
        }

        $checker = app(ReleaseStatus::class);
        $checker->refreshSoundOnStatuses(app(SessionManager::class));
        $this->uploadMessage = $checker->syncMessage;
        if ($checker->bulkCheckRequested) {
            $this->showWorkerOperationSuccess('Verification & check worker dinyalakan', $this->uploadMessage ?? 'Pemeriksaan diantrikan.');
        }
    }

    public function stopCheckWorker(LocalSystemManager $system): void
    {
        Gate::authorize('automation.run');
        $this->operationError = null;
        $this->uploadMessage = null;

        DB::transaction(function (): void {
            $stoppedAt = now();
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

            ReleaseJob::query()
                ->where('error_code', 'DUPLICATE_CHECK_PENDING')
                ->update([
                    'status' => ReleaseJobStatus::NeedsAttention,
                    'progress_label' => 'Pemeriksaan duplikat dihentikan oleh operator',
                    'error_code' => 'DUPLICATE_CHECK_FAILED',
                    'error_message' => 'Pemeriksaan duplikat dihentikan.',
                    'finished_at' => $stoppedAt,
                ]);

            DB::table('jobs')->where('queue', 'status-checks')->delete();
        });

        $system->stopQueueWorkers(['status-checks']);

        $this->uploadMessage = 'Verification & check worker dihentikan.';
        $this->showWorkerOperationSuccess('Verification & check worker dihentikan', $this->uploadMessage);
    }

    public function closeWorkerOperationModal(): void
    {
        $this->showWorkerOperationModal = false;
    }

    public function retryRelease(string $jobId): void
    {
        Gate::authorize('automation.retry');
        $this->operationError = null;
        $this->uploadMessage = null;

        $job = ReleaseJob::with('automationRun')->findOrFail($jobId);
        abort_if(str_starts_with((string) $job->error_code, 'DUPLICATE_'), 409, 'Pemeriksaan duplikat harus diselesaikan sebelum rilisan dapat diproses.');
        abort_unless(in_array($job->status, [
            ReleaseJobStatus::Failed,
            ReleaseJobStatus::NeedsAttention,
            ReleaseJobStatus::ManualAuthRequired,
        ], true), 409);

        $job->update([
            'status' => ReleaseJobStatus::Queued,
            'error_code' => null,
            'error_message' => null,
            'finished_at' => null,
            'progress_label' => 'Menunggu giliran proses',
            'progress_updated_at' => now(),
        ]);
        
        $summary = $job->automationRun->summary_json ?? [];
        $summary['duplicate_check_completed'] = false;
        $summary['fresh_duplicate_check_job_ids'] = collect($summary['fresh_duplicate_check_job_ids'] ?? [])
            ->push((string) $job->id)
            ->unique()
            ->values()
            ->all();
        
        $job->automationRun->update([
            'status' => AutomationRunStatus::Running,
            'stop_requested_at' => null,
            'finished_at' => null,
            'summary_json' => $summary,
        ]);
        app(OperatorAudit::class)->record(
            'job_retry_requested',
            'Operator requested release job retry from dashboard.',
            $job->automation_run_id,
            $job->id,
        );
        ProcessReleaseJob::dispatch($job->id);
        $this->uploadMessage = "Retry {$job->release_title} berhasil diantrikan dari checkpoint terakhir.";
    }

    /** Force an upload only after an operator explicitly accepts a duplicate release. */
    public function forceDuplicateUpload(string $jobId): void
    {
        Gate::authorize('automation.retry');
        $this->operationError = null;
        $this->uploadMessage = null;

        $job = ReleaseJob::with('automationRun')->findOrFail($jobId);
        abort_unless($job->error_code === 'DUPLICATE_RELEASE_FOUND', 409, 'Hanya rilisan yang terdeteksi duplikat yang dapat dipaksa upload.');
        abort_if(filled($job->soundon_draft_id), 409, 'Rilisan ini sudah memiliki draft SoundOn.');

        DB::transaction(function () use ($job): void {
            $run = $job->automationRun;
            $summary = $run->summary_json ?? [];
            $summary['force_duplicate_upload_job_ids'] = collect($summary['force_duplicate_upload_job_ids'] ?? [])
                ->push((string) $job->id)
                ->unique()
                ->values()
                ->all();

            $run->update([
                'status' => AutomationRunStatus::Running,
                'stop_requested_at' => null,
                'finished_at' => null,
                'summary_json' => $summary,
            ]);
            $job->update([
                'status' => ReleaseJobStatus::Queued,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => null,
                'progress_percent' => 45,
                'progress_label' => 'Upload paksa disetujui operator — menunggu giliran',
                'progress_updated_at' => now(),
            ]);
        });

        app(OperatorAudit::class)->record(
            'duplicate_upload_forced',
            'Operator explicitly approved a duplicate release for upload.',
            $job->automation_run_id,
            $job->id,
        );
        ProcessReleaseJob::dispatch($job->id);
        $this->uploadMessage = "{$job->release_title} diantrikan untuk tetap di-upload ke SoundOn.";
    }

    public function render()
    {
        // Recover runs created by an older worker version that collected all
        // rows and finished duplicate checks but remained `queued` forever.
        AutomationRun::query()
            ->where('status', AutomationRunStatus::Queued)
            ->where('summary_json->collection_pending', false)
            ->where('summary_json->duplicate_check_completed', true)
            ->whereHas('releaseJobs')
            ->update([
                'status' => AutomationRunStatus::Completed,
                'finished_at' => now(),
            ]);

        AutomationRun::query()
            ->where('status', AutomationRunStatus::Queued)
            ->where('pending_found', 0)
            ->where('started_at', '<', now()->subMinutes(3))
            ->whereDoesntHave('releaseJobs')
            ->get()
            ->each(function (AutomationRun $run): void {
                $summary = $run->summary_json ?? [];
                $summary['collection_pending'] = false;
                if (empty($summary['error'])) {
                    $summary['error'] = 'Pengambilan rilisan terputus sebelum selesai. Browser worker mungkin tidak aktif atau timeout. Coba jalankan kembali.';
                }
                $run->update([
                    'status' => AutomationRunStatus::Failed,
                    'finished_at' => now(),
                    'summary_json' => $summary,
                ]);
            });

        $monitorRun = $this->monitorRunId
            ? $this->visibleRunsQuery()->find($this->monitorRunId)
            : $this->visibleRunsQuery()->latest()->first();
        $this->monitorRunId = $monitorRun?->id;
        $pendingReleaseIds = array_map('strval', data_get($monitorRun?->summary_json, 'pending_release_ids', []));
        if ($pendingReleaseIds === [] && $monitorRun) {
            $pendingReleaseIds = $monitorRun->releaseJobs()->pluck('soundfresh_release_id')->map('strval')->all();
        }
        $releaseJobs = $pendingReleaseIds === []
            ? collect()
            : ReleaseJob::query()
                ->with('automationRun')
                ->whereIn('soundfresh_release_id', $pendingReleaseIds)
                ->latest()
                ->get()
                ->unique('soundfresh_release_id')
                ->sortBy(fn (ReleaseJob $job) => array_search((string) $job->soundfresh_release_id, $pendingReleaseIds, true))
                ->values();
        // "Selesai" means a draft really exists in SoundOn. Failed and
        // needs-attention jobs remain unfinished and must not advance the
        // overall progress bar.
        $processedCount = $releaseJobs
            ->filter(fn (ReleaseJob $job) => filled($job->soundon_draft_id))
            ->count();
        $latestReleaseCount = $monitorRun?->pending_found ?? 0;
        $selectableReleaseJobIds = $releaseJobs
            ->filter(fn (ReleaseJob $job) => $this->isSelectable($job))
            ->pluck('id')->map('strval')->values()->all();
        $activeJobs = $releaseJobs
            ->filter(fn (ReleaseJob $job) => $job->status === ReleaseJobStatus::Running)
            ->values();
        $systemStatus = app(LocalSystemManager::class)->status();
        $duplicateCheckingCount = $releaseJobs->where('error_code', 'DUPLICATE_CHECK_PENDING')->count();
        $duplicateCheckingJob = $releaseJobs->first(fn (ReleaseJob $job) => $job->error_code === 'DUPLICATE_CHECK_PENDING');
        $duplicateProgressPercent = $duplicateCheckingCount > 0
            ? (int) round($releaseJobs->where('error_code', 'DUPLICATE_CHECK_PENDING')->avg(fn (ReleaseJob $job) => (int) ($job->progress_percent ?? 0)))
            : 0;
        $queuedCount = $releaseJobs->filter(fn (ReleaseJob $job) => $job->status === ReleaseJobStatus::Queued
            && $job->error_code !== 'DUPLICATE_CHECK_PENDING')->count();
        $pendingSoundfreshCount = $releaseJobs
            ->filter(fn (ReleaseJob $job): bool => $job->soundfresh_workflow_status === 'pending')
            ->count();
        $soundonUploadSuccessCount = $releaseJobs
            ->filter(fn (ReleaseJob $job): bool => filled($job->soundon_draft_id))
            ->count();
        $soundonUploadFailedCount = $releaseJobs
            ->filter(fn (ReleaseJob $job): bool => blank($job->soundon_draft_id)
                && in_array($job->status->value, ['failed', 'needs_attention', 'manual_auth_required'], true))
            ->count();

        $filteredReleaseJobs = match ($this->releaseTab) {
            'uploaded' => $releaseJobs->filter(fn (ReleaseJob $job) => filled($job->soundon_draft_id))->values(),
            'failed' => $releaseJobs->filter(fn (ReleaseJob $job) => blank($job->soundon_draft_id) && in_array($job->status->value, ['failed', 'needs_attention', 'manual_auth_required'], true))->values(),
            default => $releaseJobs,
        };

        $workerOnline = $systemStatus['worker'];
        $activeRunsCount = AutomationRun::query()
            ->whereIn('status', [AutomationRunStatus::Running, AutomationRunStatus::Queued])
            ->count();
        $activeJobsCount = ReleaseJob::query()
            ->where('status', ReleaseJobStatus::Running)
            ->count();
        $verificationActiveCount = ReleaseJob::query()
            ->whereIn('soundfresh_verify_status', ['sending', 'rejecting'])
            ->count();
        $statusCheckActiveCount = ReleaseJob::query()
            ->whereIn('soundon_check_status', ['queued', 'checking'])
            ->count();
        $dbQueueCount = DB::table('jobs')->whereIn('queue', ['release-automation', 'status-checks'])->count();
        $totalActiveWorkers = ($activeRunsCount > 0 ? 1 : 0) + ($activeJobsCount > 0 ? 1 : 0) + ($verificationActiveCount > 0 ? 1 : 0) + ($statusCheckActiveCount > 0 ? 1 : 0);

        return view('livewire.automation.dashboard', [
            'runs' => $this->visibleRunsQuery()->latest()->withCount('releaseJobs')->limit(20)->get(),
            'releaseJobs' => $filteredReleaseJobs,
            'totalReleaseJobsCount' => $releaseJobs->count(),
            'uploadedCount' => $soundonUploadSuccessCount,
            'failedCount' => $soundonUploadFailedCount,
            'releaseCount' => $latestReleaseCount,
            'draftCount' => $releaseJobs->whereNotNull('soundon_draft_id')->count(),
            'processedCount' => $processedCount,
            'progressPercent' => $latestReleaseCount > 0 ? min(100, (int) round(($processedCount / $latestReleaseCount) * 100)) : 0,
            'activeJobs' => $activeJobs,
            'systemStatus' => $systemStatus,
            'queuedCount' => $queuedCount,
            'duplicateCheckingCount' => $duplicateCheckingCount,
            'duplicateCheckingJob' => $duplicateCheckingJob,
            'duplicateProgressPercent' => $duplicateProgressPercent,
            'pendingSoundfreshCount' => $pendingSoundfreshCount,
            'soundonUploadSuccessCount' => $soundonUploadSuccessCount,
            'soundonUploadFailedCount' => $soundonUploadFailedCount,
            'monitorRun' => $monitorRun,
            'selectableReleaseJobIds' => $selectableReleaseJobIds,
            'accounts' => AutomationAccount::all(),
            'workerOnline' => $workerOnline,
            'activeRunsCount' => $activeRunsCount,
            'activeJobsCount' => $activeJobsCount,
            'verificationActiveCount' => $verificationActiveCount,
            'statusCheckActiveCount' => $statusCheckActiveCount,
            'dbQueueCount' => $dbQueueCount,
            'totalActiveWorkers' => $totalActiveWorkers,
        ]);
    }

    /** @return array<int, string> */
    private function selectableReleaseJobIds(): array
    {
        $monitorRun = $this->monitorRunId
            ? $this->visibleRunsQuery()->find($this->monitorRunId)
            : $this->visibleRunsQuery()->latest()->first();
        $pendingReleaseIds = array_map('strval', data_get($monitorRun?->summary_json, 'pending_release_ids', []));
        if ($pendingReleaseIds === [] && $monitorRun) {
            $pendingReleaseIds = $monitorRun->releaseJobs()->pluck('soundfresh_release_id')->map('strval')->all();
        }

        return ReleaseJob::query()
            ->whereIn('soundfresh_release_id', $pendingReleaseIds ?: [''])
            ->whereNull('soundon_draft_id')
            ->where(function ($query): void {
                $query->whereNull('error_code')->orWhereNotIn('error_code', [
                    'DUPLICATE_CHECK_PENDING', 'DUPLICATE_CHECK_FAILED', 'DUPLICATE_RELEASE_FOUND',
                ]);
            })
            ->whereIn('status', ['queued', 'completed', 'needs_attention', 'failed'])
            ->latest()
            ->get()
            ->unique('soundfresh_release_id')
            ->pluck('id')->map('strval')->values()->all();
    }

    private function isSelectable(ReleaseJob $job): bool
    {
        return blank($job->soundon_draft_id)
            && ! in_array($job->error_code, ['DUPLICATE_CHECK_PENDING', 'DUPLICATE_CHECK_FAILED', 'DUPLICATE_RELEASE_FOUND'], true)
            && in_array($job->status->value, ['queued', 'completed', 'needs_attention', 'failed'], true);
    }

    private function showWorkerOperationSuccess(string $title, string $message): void
    {
        $this->workerOperationTitle = $title;
        $this->workerOperationMessage = $message;
        $this->showWorkerOperationModal = true;
    }

    private function visibleRunsQuery()
    {
        return AutomationRun::query()
            ->whereNotIn('status', [AutomationRunStatus::Stopped, AutomationRunStatus::Cancelled])
            ->where(function ($query): void {
                $query->whereNull('summary_json->purpose')
                    ->orWhere('summary_json->purpose', '!=', 'soundon_status_check');
            });
    }
}
