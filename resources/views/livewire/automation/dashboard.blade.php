<div wire:poll.2s.keep-alive="refreshMonitor">
    <x-automation-nav />

    <main class="page-shell">
        @if ($configurationError)
            <div role="alert" class="mb-6 info-banner" style="border-color:#f2d49b;background:#fff9ec;color:#8c5a11">
                <div><strong>Konfigurasi belum lengkap.</strong> {{ $configurationError }} <a class="font-semibold underline" href="{{ route('automation.sessions') }}">Buka Pengaturan</a></div>
            </div>
        @endif

        @if ($uploadMessage)
            <div role="status" class="mb-6 info-banner">
                <div><strong>Upload draft SoundOn.</strong> {{ $uploadMessage }}</div>
            </div>
        @endif

        @if ($operationError)
            <div role="alert" class="mb-6 info-banner" style="border-color:#f4b8bf;background:#fff1f3;color:#a72f3d">
                <div><strong>Proses tidak dapat dilanjutkan.</strong> {{ $operationError }}</div>
            </div>
        @endif

        <section class="page-heading">
            <div>
                <div class="eyebrow">Operations overview</div>
                <h1 class="page-title">SoundOn Release Automation</h1>
                <p class="page-subtitle">Pantau pipeline {{ $cmsName }} ke SoundOn, status sesi, dan pekerjaan yang membutuhkan tindakan dari satu tempat.</p>
            </div>
            @can('automation.run')
                <div class="dashboard-primary-actions">
                    <div class="flex flex-wrap items-center gap-3">
                    <button wire:click="start" wire:loading.attr="disabled" wire:confirm="Bersihkan seluruh data monitor, status SoundOn, notifikasi, dan antrean lama lalu ambil ulang semua rilisan pending?" class="primary-button">
                        <span wire:loading.remove wire:target="start">Ambil semua rilisan pending</span>
                        <span wire:loading wire:target="start">Menyiapkan...</span>
                    </button>
                    <span class="primary-button" style="background:#0f766e; cursor:default" role="status">
                        Upload otomatis aktif
                    </span>
                    <button type="button" wire:click="stopAllWorkers" wire:loading.attr="disabled" wire:confirm="Yakin ingin menghentikan SEMUA worker, antrean, dan proses automation yang sedang berjalan?" class="secondary-button" style="border-color:#f4b8bf;background:#fff1f3;color:#a72f3d;font-weight:700;">
                        <span wire:loading.remove wire:target="stopAllWorkers">🛑 Hentikan Semua Worker</span>
                        <span wire:loading wire:target="stopAllWorkers">Menghentikan...</span>
                    </button>
                    </div>
                    <p class="dashboard-action-hint">Semua rilisan nonduplikat langsung di-upload ke SoundOn setelah pemeriksaan duplikat selesai. Rilisan duplikat tetap diblokir.</p>
                </div>
            @endcan
        </section>

        <section class="metric-grid">
            <article class="metric-card" style="--metric-soft:#eeeeff">
                <p class="metric-label">Pending {{ $cmsName }}</p>
                <p class="metric-value">{{ $pendingSoundfreshCount }}</p>
                <p class="metric-meta">Rilisan Pending pada monitor aktif</p>
            </article>
            <article class="metric-card" style="--metric-soft:#fff4df">
                <p class="metric-label">Berhasil upload ke SoundOn</p>
                <p class="metric-value" style="color:#047857">{{ $soundonUploadSuccessCount }}</p>
                <p class="metric-meta">Berhasil tersimpan sebagai draft SoundOn</p>
            </article>
            <article class="metric-card" style="--metric-soft:#e8f8f2">
                <p class="metric-label">Gagal upload ke SoundOn</p>
                <p class="metric-value" style="color:#dc2626">{{ $soundonUploadFailedCount }}</p>
                <p class="metric-meta">Rilisan gagal atau membutuhkan perhatian</p>
            </article>
            <article class="metric-card" style="--metric-soft:#edf4ff">
                <p class="metric-label">Kesehatan platform</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    @forelse ($accounts as $account)
                        <span class="badge {{ $account->status === 'active' ? 'badge--success' : 'badge--warning' }}">
                            <span class="status-dot {{ $account->status === 'active' ? 'status-dot--active' : 'status-dot--warning' }}"></span>
                            {{ $account->platform->value }} · {{ $account->status }}
                        </span>
                    @empty
                        <span class="text-xs text-slate-400">Belum diperiksa</span>
                    @endforelse
                </div>
                <p class="metric-meta">Diperbarui otomatis setiap 10 detik</p>
            </article>
        </section>

        <section class="panel mt-6">
            <header class="panel-header flex flex-wrap items-center justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="panel-title">Worker & Process Aktif Saat Ini</h2>
                        @if($totalActiveWorkers > 0 || $workerOnline)
                            <span class="badge badge--success">
                                <span class="status-dot status-dot--active"></span>
                                Worker Aktif
                            </span>
                        @else
                            <span class="badge badge--warning">
                                <span class="status-dot status-dot--warning"></span>
                                Worker Standby
                            </span>
                        @endif
                    </div>
                    <p class="panel-copy">Status worker browser, proses antrean pipeline, dan kontrol penghentian worker untuk menghindari bentrok.</p>
                </div>
                @can('automation.run')
                    <div>
                        <button type="button" 
                            wire:click="stopAllWorkers" 
                            wire:loading.attr="disabled" 
                            wire:confirm="Yakin ingin menghentikan SEMUA worker, antrean, dan proses automation yang sedang berjalan?" 
                            class="secondary-button" 
                            style="border-color:#f4b8bf;background:#fff1f3;color:#a72f3d;font-weight:700;">
                            <span wire:loading.remove wire:target="stopAllWorkers">🛑 Hentikan Semua Worker</span>
                            <span wire:loading wire:target="stopAllWorkers">Menghentikan...</span>
                        </button>
                    </div>
                @endcan
            </header>

            <div class="worker-grid" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; padding: 20px;">
                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Browser Worker</span>
                            @if($workerOnline)
                                <span class="badge badge--success text-xs"><span class="status-dot status-dot--active"></span>Online</span>
                            @else
                                <span class="badge badge--danger text-xs"><span class="status-dot status-dot--warning"></span>Offline</span>
                            @endif
                        </div>
                        <p class="text-sm font-bold text-slate-800">Node.js Service (Port {{ parse_url(config('automation.worker_url'), PHP_URL_PORT) ?: 3112 }})</p>
                        <p class="text-xs text-slate-500 mt-1">Mengendalikan browser headless Playwright.</p>
                    </div>
                    @can('automation.run')
                        <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100">
                            <button type="button" wire:click="startWorkerProcess" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:600;">
                                <span>▶ Start Worker</span>
                            </button>
                            <button type="button" wire:click="stopWorkerProcess" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;font-weight:600;">
                                <span>⏹ Stop Worker</span>
                            </button>
                        </div>
                    @endcan
                </div>

                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Pipeline Queue Worker</span>
                            @if($activeRunsCount > 0 || $activeJobsCount > 0)
                                <span class="badge badge--brand text-xs">{{ $activeRunsCount }} Run Aktif</span>
                            @else
                                <span class="badge text-xs text-slate-500 bg-slate-100">Idle</span>
                            @endif
                        </div>
                        <p class="text-sm font-bold text-slate-800">{{ $activeJobsCount }} Diproses · {{ $queuedCount }} Menunggu</p>
                        <p class="text-xs text-slate-500 mt-1">Queue: `release-automation`</p>
                    </div>
                    @can('automation.run')
                        <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100">
                            <button type="button" wire:click="startQueueWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:600;">
                                <span>▶ Start Queue</span>
                            </button>
                            <button type="button" wire:click="stopQueueWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;font-weight:600;">
                                <span>⏹ Stop Queue</span>
                            </button>
                        </div>
                    @endcan
                </div>

                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <span class="text-xs font-semibold text-slate-500 uppercase tracking-wider">Verification & Check Worker</span>
                            @if($verificationActiveCount > 0 || $statusCheckActiveCount > 0)
                                <span class="badge badge--warning text-xs">Memeriksa</span>
                            @else
                                <span class="badge text-xs text-slate-500 bg-slate-100">Idle</span>
                            @endif
                        </div>
                        <p class="text-sm font-bold text-slate-800">{{ $verificationActiveCount }} Verify · {{ $statusCheckActiveCount }} Status Check</p>
                        <p class="text-xs text-slate-500 mt-1">Queue: `status-checks`</p>
                    </div>
                    @can('automation.run')
                        <div class="mt-4 flex items-center gap-2 pt-3 border-t border-slate-100">
                            <button type="button" wire:click="startCheckWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d;font-weight:600;">
                                <span>▶ Start Checker</span>
                            </button>
                            <button type="button" wire:click="stopCheckWorker" wire:loading.attr="disabled" class="secondary-button text-xs py-1.5 px-3 flex-1 flex items-center justify-center gap-1" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c;font-weight:600;">
                                <span>⏹ Stop Checker</span>
                            </button>
                        </div>
                    @endcan
                </div>
            </div>
        </section>

        <section class="panel mt-6">
            <header class="panel-header">
                <div>
                    <h2 class="panel-title">Monitor rilisan</h2>
                    <p class="panel-copy">Status setiap rilisan sejak ditemukan di {{ $cmsName }} sampai disimpan sebagai draft di SoundOn.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="badge badge--brand">{{ $releaseCount }} rilisan diambil</span>
                    <span class="badge badge--success">{{ $draftCount }} draft SoundOn</span>
                    <span class="badge {{ $systemStatus['worker'] ? 'badge--success' : 'badge--danger' }}">Browser worker: {{ $systemStatus['worker'] ? 'Online' : 'Offline' }}</span>
                    <span class="badge badge--brand">Worker proses: {{ $activeJobs->count() }} / {{ $systemStatus['browser_slots'] }}</span>
                </div>
            </header>
            @if($activeJobs->isNotEmpty())
                @php
                    $activePercent = max(5, (int) round($activeJobs->avg(fn ($job) => (int) ($job->progress_percent ?? 5))));
                @endphp
                <div class="border-t border-indigo-100 bg-indigo-50 px-5 py-4" role="status" aria-live="polite">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 text-sm font-bold text-indigo-900">
                                <span class="inline-block h-3 w-3 animate-pulse rounded-full bg-indigo-600"></span>
                                {{ $activeJobs->count() }} dari {{ $systemStatus['browser_slots'] }} worker sedang memproses
                            </div>
                            @foreach($activeJobs as $activeJob)
                                @php
                                    $activeTitle = filled($activeJob->release_title) && strtolower($activeJob->release_title) !== 'view' ? $activeJob->release_title : 'Rilisan #'.$activeJob->soundfresh_release_id;
                                    $activeSince = $activeJob->progress_updated_at ?? $activeJob->updated_at;
                                @endphp
                                <p class="mt-1 text-xs font-semibold text-indigo-700">Worker {{ $loop->iteration }}: {{ $activeTitle }} — {{ $activeJob->progress_label ?: 'Menyiapkan proses rilisan' }}</p>
                                <p class="mt-1 text-[11px] text-indigo-500">ID {{ $activeJob->soundfresh_release_id }}@if($activeJob->artist_name) · {{ $activeJob->artist_name }}@endif · tahap diperbarui {{ $activeSince?->diffForHumans() ?? 'baru saja' }}</p>
                            @endforeach
                        </div>
                        <div class="text-right text-xs text-indigo-700">
                            <strong class="block text-lg">{{ $activePercent }}%</strong>
                            <span>{{ $queuedCount }} rilisan menunggu giliran</span>
                        </div>
                    </div>
                    <div class="mt-3 h-3 overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-label="Progress rilisan aktif {{ $activeTitle }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $activePercent }}">
                        <div class="h-full animate-pulse rounded-full bg-indigo-600 transition-all duration-500" style="width: {{ $activePercent }}%"></div>
                    </div>
                    <p class="mt-2 text-[11px] text-indigo-500">Pengisian form SoundOn melalui browser dapat membutuhkan beberapa menit. Panel ini berubah otomatis setiap 3 detik saat worker berpindah tahap atau rilisan.</p>
                </div>
            @elseif($duplicateCheckingCount > 0)
                <div class="border-t border-indigo-100 bg-indigo-50 px-5 py-3 text-xs font-semibold text-indigo-800" role="status" aria-live="polite">
                    <div class="flex items-center justify-between gap-3">
                        <span>Memeriksa duplikat {{ $duplicateCheckingCount }} rilisan di {{ $cmsName }}, All releases SoundOn, dan Drafts SoundOn</span>
                        <strong>{{ $duplicateProgressPercent }}%</strong>
                    </div>
                    <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $duplicateProgressPercent }}">
                        <div class="h-full rounded-full bg-indigo-600 transition-all duration-500" style="width: {{ $duplicateProgressPercent }}%"></div>
                    </div>
                    <div class="mt-2 font-normal text-indigo-700">{{ $duplicateCheckingJob?->progress_label ?? 'Menyiapkan pemeriksaan duplikat' }}</div>
                </div>
            @elseif($queuedCount > 0)
                <div class="border-t border-amber-100 bg-amber-50 px-5 py-3 text-xs font-semibold text-amber-800" role="status">
                    Tidak ada worker yang sedang memproses; {{ $queuedCount }} rilisan masih menunggu. Sistem pemulihan akan menjalankan kembali antrean saat aplikasi aktif.
                </div>
            @endif

            <div class="border-t border-slate-100 px-5 pt-3 pb-0 flex items-center gap-2 bg-slate-50/50">
                <button type="button" wire:click="setReleaseTab('all')" class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all {{ $releaseTab === 'all' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-200/60' }}">
                    Semua ({{ $totalReleaseJobsCount }})
                </button>
                <button type="button" wire:click="setReleaseTab('uploaded')" class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all {{ $releaseTab === 'uploaded' ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-200/60' }}">
                    Selesai terupload ({{ $uploadedCount }})
                </button>
                <button type="button" wire:click="setReleaseTab('failed')" class="px-3 py-1.5 text-xs font-bold rounded-lg transition-all {{ $releaseTab === 'failed' ? 'bg-red-600 text-white shadow-sm' : 'text-slate-600 hover:bg-slate-200/60' }}">
                    Gagal ({{ $failedCount }})
                </button>
            </div>

            <div wire:loading.flex wire:target="start" class="flex-col gap-3 border-t border-indigo-100 bg-indigo-50 px-5 py-4 text-sm font-semibold text-indigo-700">
                <div class="flex items-center gap-3">
                    <span class="inline-block h-4 w-4 animate-spin rounded-full border-2 border-indigo-200 border-t-indigo-600"></span>
                    Membersihkan monitor dan mengambil rilisan terbaru dari {{ $cmsName }}...
                </div>
                <div class="h-2 w-full overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-label="Mengambil rilisan terbaru">
                    <div class="h-full w-2/3 animate-pulse rounded-full bg-indigo-600"></div>
                </div>
            </div>
            <div wire:loading.remove wire:target="start" class="border-t border-slate-100 px-5 py-4">
                <div class="mb-2 flex items-center justify-between gap-3 text-xs font-semibold text-slate-500">
                    <span>Progress rilisan terbaru</span>
                    <span>{{ $processedCount }} / {{ $releaseCount }} selesai ({{ $progressPercent }}%)</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progressPercent }}">
                    <div class="h-full rounded-full bg-indigo-600 transition-all duration-500" style="width: {{ $progressPercent }}%"></div>
                </div>
            </div>
            <div wire:loading.remove wire:target="start" class="table-scroll">
                <table class="data-table release-monitor-table">
                    <colgroup>
                        <col class="release-col--title">
                        <col class="release-col--source">
                        <col class="release-col--soundon">
                        <col class="release-col--progress">
                        <col class="release-col--status">
                        <col class="release-col--updated">
                    </colgroup>
                    <thead><tr><th>Rilisan</th><th>Status {{ $cmsName }}</th><th>Status SoundOn</th><th>Progress</th><th>Status Proses</th><th>Diperbarui</th></tr></thead>
                    <tbody wire:key="release-monitor-body-{{ $monitorRun?->id ?? 'none' }}-{{ $releaseJobs->isEmpty() ? 'empty' : 'populated' }}">
                    @forelse ($releaseJobs as $job)
                        @php
                            $jobStatus = $job->status->value;
                            $checkpoint = $job->checkpoint->value;
                            $hasMetadata = $job->metadata_snapshot_json !== null;
                            $hasDraft = filled($job->soundon_draft_id);
                            $assetProgress = $job->asset_progress_json ?? [];
                            $displayTitle = filled($job->release_title) && strtolower($job->release_title) !== 'view' ? $job->release_title : 'Rilisan #'.$job->soundfresh_release_id;
                            $releaseTypeLabel = match ($job->release_type) {
                                'ep', 'album' => 'EP/Album',
                                'single' => 'Single',
                                default => ((int) ($job->track_count ?? 1) > 1 ? 'EP/Album' : 'Single'),
                            };
                            $checkpointProgress = [
                                'discovered' => 5,
                                'metadata_extracted' => 25,
                                'assets_downloaded' => 45,
                                'soundon_draft_created' => 60,
                                'metadata_filled' => 70,
                                'assets_uploaded' => 85,
                                'draft_saved' => 95,
                                'soundfresh_reviewed' => 95,
                                'completed' => 100,
                            ];
                            $jobProgress = $jobStatus === 'completed' ? 100 : max((int) ($job->progress_percent ?? 0), ($checkpointProgress[$checkpoint] ?? 0));
                            $progressTone = in_array($jobStatus, ['failed', 'needs_attention', 'manual_auth_required', 'stopped'], true) ? 'release-progress--danger' : ($jobStatus === 'completed' ? 'release-progress--success' : '');
                            $progressLabel = ($jobStatus === 'running' || $job->error_code === 'DUPLICATE_CHECK_PENDING') && filled($job->progress_label) ? $job->progress_label : ($jobStatus === 'stopped' ? 'Proses dihentikan' : ($jobStatus === 'queued' ? match ($checkpoint) {
                                'discovered' => 'Menunggu giliran ekstraksi',
                                'metadata_extracted' => 'Metadata siap — menunggu unduh aset',
                                'assets_downloaded' => 'Aset siap — menunggu giliran SoundOn',
                                'soundon_draft_created' => 'Draft siap — menunggu pengisian',
                                'metadata_filled' => 'Metadata siap — menunggu upload aset',
                                'assets_uploaded' => 'Upload siap — menunggu simpan draft',
                                'draft_saved', 'soundfresh_reviewed' => 'Draft siap — menunggu penyelesaian',
                                'completed' => 'Selesai',
                                default => 'Menunggu giliran',
                            } : match ($checkpoint) {
                                'discovered' => 'Memulai proses',
                                'metadata_extracted' => 'Metadata diekstrak',
                                'assets_downloaded' => 'Aset diunduh',
                                'soundon_draft_created' => 'Draft dibuat',
                                'metadata_filled' => 'Metadata diisi',
                                'assets_uploaded' => 'Aset diunggah',
                                'draft_saved' => 'Draft disimpan',
                                'soundfresh_reviewed' => 'Review selesai',
                                'completed' => 'Selesai',
                                default => str_replace('_', ' ', $checkpoint),
                            }));
                            $statusLabel = match ($jobStatus) {
                                'queued' => 'Menunggu giliran',
                                'running' => 'Sedang diproses',
                                'completed' => 'Selesai',
                                'failed' => 'Gagal',
                                'needs_attention' => 'Perlu perhatian',
                                'manual_auth_required' => 'Perlu login ulang',
                                'stopped' => 'Dihentikan',
                                default => str_replace('_', ' ', $jobStatus),
                            };
                        @endphp
                        <tr wire:key="release-monitor-{{ $job->id }}">
                            <td><div class="min-w-52"><a href="{{ $job->soundfresh_release_url }}" target="_blank" rel="noopener noreferrer">{{ $displayTitle }} ↗</a><div class="mt-1 flex flex-wrap items-center gap-1.5"><span class="badge badge--brand">{{ $releaseTypeLabel }}</span><span class="text-[11px] text-slate-400">ID {{ $job->soundfresh_release_id }}@if($job->artist_name) · {{ $job->artist_name }}@endif</span></div></div></td>
                            <td><span class="badge badge--success"><span class="status-dot status-dot--active"></span>Sudah diambil</span><p class="mt-1 text-[11px] text-slate-400">{{ $hasMetadata ? 'Metadata berhasil dibaca' : 'Menunggu ekstraksi metadata' }}</p></td>
                            <td>
                                @if ($hasDraft)
                                    @php
                                        $draftLabel = $job->soundon_draft_status === 'already_exists'
                                            ? 'Sudah ada di draft SoundOn'
                                            : 'Draft tersimpan';
                                    @endphp
                                    @if ($job->soundon_draft_url)<a href="{{ $job->soundon_draft_url }}" target="_blank" rel="noopener noreferrer" class="badge badge--success">{{ $draftLabel }} ↗</a>@else<span class="badge badge--success">{{ $draftLabel }}</span>@endif
                                    <p class="mt-1 text-[11px] text-slate-400">Draft ID {{ $job->soundon_draft_id }}</p>
                                @else
                                    <span class="badge {{ in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) ? 'badge--danger' : 'badge--warning' }}">Belum diunggah</span><p class="mt-1 max-w-44 text-[11px] text-slate-400">{{ in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && $job->error_code ? \App\Support\SystemBranding::text($job->error_code) : 'Menunggu proses SoundOn' }}</p>
                                @endif
                            </td>
                            <td>
                                <div class="release-progress {{ $progressTone }}">
                                    <div class="release-progress__copy"><span>{{ $progressLabel }}</span><strong>{{ $jobProgress }}%</strong></div>
                                    <div class="release-progress__track" role="progressbar" aria-label="Progress {{ $displayTitle }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $jobProgress }}">
                                        <div class="release-progress__bar" style="width: {{ $jobProgress }}%"></div>
                                    </div>
                                </div>
                                @if(!empty($assetProgress))
                                    <div class="mt-2 grid gap-1 text-[10px] text-slate-500" aria-label="Status unduhan aset {{ $displayTitle }}">
                                        @foreach(['cover', 'audio', 'tiktok_audio'] as $assetType)
                                            @php
                                                $assetItem = $assetProgress[$assetType] ?? ['label' => match($assetType) { 'cover' => 'Cover Art', 'audio' => 'Audio Master', default => 'Audio Clip' }, 'status' => 'pending'];
                                                $assetStatus = $assetItem['status'] ?? 'pending';
                                                $assetIcon = match($assetStatus) { 'completed' => '✓', 'downloading' => '↓', 'failed' => '!', 'unavailable' => '—', default => '○' };
                                                $assetCopy = match($assetStatus) { 'completed' => 'selesai', 'downloading' => 'sedang diunduh', 'failed' => 'gagal', 'unavailable' => 'tidak tersedia', default => 'menunggu' };
                                                $assetError = $assetStatus === 'failed' ? ($assetItem['error'] ?? null) : null;
                                            @endphp
                                            <div title="{{ $assetError }}" class="flex items-center gap-1.5 {{ $assetStatus === 'completed' ? 'font-semibold text-emerald-600' : ($assetStatus === 'failed' ? 'font-semibold text-red-600' : ($assetStatus === 'downloading' ? 'font-semibold text-indigo-600' : '')) }}">
                                                <span aria-hidden="true">{{ $assetIcon }}</span>
                                                <span>{{ $assetItem['label'] }} — {{ $assetCopy }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td>
                                <span title="{{ $jobStatus === 'queued' ? 'Rilisan sudah tersimpan di antrean dan akan diproses setelah rilisan aktif selesai.' : ($jobStatus === 'running' ? 'Rilisan ini sedang dikerjakan oleh automation worker.' : '') }}" class="badge {{ $jobStatus === 'completed' ? 'badge--success' : (in_array($jobStatus, ['failed','needs_attention','manual_auth_required','stopped']) ? 'badge--danger' : 'badge--warning') }}">{{ $statusLabel }}</span>
                                @if(in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && $job->error_message)
                                    <div class="release-error-box mt-2 rounded-lg border border-red-100 bg-red-50 p-2 text-[11px] text-red-700" title="{{ \App\Support\SystemBranding::text($job->error_message) }}">
                                        @php
                                            $readableError = \App\Services\Automation\ReadableReleaseError::explain($job->error_code, $job->error_message);
                                        @endphp
                                        <strong class="block">{{ $readableError['title'] }}</strong>
                                        <p class="mt-1">{{ $readableError['reason'] }}</p>
                                        @if($readableError['action'])
                                            <p class="mt-2">{{ $readableError['action'] }}</p>
                                        @endif
                                        <details class="mt-2"><summary class="cursor-pointer">Detail teknis</summary><p class="mt-1 break-words">{{ \App\Support\SystemBranding::text($job->error_code) }} — {{ \App\Support\SystemBranding::text($job->error_message) }}</p></details>
                                    </div>
                                @endif
                                @if(in_array($jobStatus, ['failed','needs_attention','manual_auth_required'], true) && !str_starts_with((string) $job->error_code, 'DUPLICATE_'))
                                    @can('automation.retry')
                                        <button type="button" wire:click="retryRelease('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="retryRelease('{{ $job->id }}')" class="secondary-button mt-2" aria-label="Retry proses {{ $displayTitle }}">
                                            <span wire:loading.remove wire:target="retryRelease('{{ $job->id }}')">Retry</span>
                                            <span wire:loading wire:target="retryRelease('{{ $job->id }}')">Mengantrekan...</span>
                                        </button>
                                    @endcan
                                @endif
                                @if($job->error_code === 'DUPLICATE_RELEASE_FOUND' && blank($job->soundon_draft_id))
                                    @can('automation.retry')
                                        <button type="button" wire:click="forceDuplicateUpload('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="forceDuplicateUpload('{{ $job->id }}')" class="secondary-button mt-2 border-amber-300 text-amber-800 hover:bg-amber-50" aria-label="Tetap upload {{ $displayTitle }} meskipun duplikat">
                                            <span wire:loading.remove wire:target="forceDuplicateUpload('{{ $job->id }}')">Tetap Upload</span>
                                            <span wire:loading wire:target="forceDuplicateUpload('{{ $job->id }}')">Mengantrekan...</span>
                                        </button>
                                        <p class="mt-1 text-[10px] text-amber-700">Melewati pemeriksaan judul dan artis yang sama.</p>
                                    @endcan
                                @endif
                            </td>
                            <td>{{ $job->updated_at?->diffForHumans() ?? '—' }}<p class="mt-1 text-[11px]"><a href="{{ route('automation.runs.show', $job->automation_run_id) }}">Buka run {{ Str::limit($job->automation_run_id, 12) }} →</a></p></td>
                        </tr>
                    @empty
                        <tr wire:key="release-monitor-empty"><td colspan="6" class="empty-state">
                            @if($monitorRun && $monitorRun->status->value === 'failed' && !empty($monitorRun->summary_json['error']))
                                <strong class="text-red-700">Pengambilan rilisan gagal:</strong> {{ $monitorRun->summary_json['error'] }}
                                <p class="mt-2 text-xs">Periksa credential {{ $cmsName }} di Pengaturan Platform, atau cek storage/logs/laravel.log untuk detail.</p>
                            @elseif($monitorRun && (in_array($monitorRun->status->value, ['queued', 'running'], true) || ($monitorRun->summary_json['collection_pending'] ?? false)))
                                Sedang mengambil rilisan {{ $cmsName }}. Daftar akan tampil otomatis tanpa refresh.
                            @elseif($monitorRun && $monitorRun->status->value === 'completed_no_pending')
                                Tidak ada rilisan pending di {{ $cmsName }} saat ini. Upload rilisan baru ke {{ $cmsName }} lalu jalankan automation lagi.
                            @else
                                Tidak ada rilisan pada pengambilan terbaru. Jalankan automation untuk mengambil pending release.
                            @endif
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <footer wire:loading.remove wire:target="start" class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4 text-xs text-slate-500"><span>Monitor hanya menampilkan {{ $releaseCount }} rilisan dari pengambilan terbaru.</span><span><strong>Catatan:</strong> sistem tidak melakukan publish otomatis; hasil akhir selalu berupa draft SoundOn.</span></footer>
        </section>

        <section class="panel mt-6">
            <header class="panel-header">
                <div><h2 class="panel-title">Aktivitas automation terbaru</h2><p class="panel-copy">Riwayat eksekusi dan progres pipeline rilisan.</p></div>
                <span class="badge badge--brand">Live · 3 detik</span>
            </header>
            <div class="table-scroll">
                <table class="data-table">
                    <thead><tr><th>Run ID</th><th>Status</th><th>Pending ditemukan</th><th>Total job</th><th>Waktu mulai</th><th>Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($runs as $run)
                        @php
                            $status = $run->status->value;
                            $runStatusLabel = match ($status) {
                                'queued' => 'Menunggu giliran',
                                'running' => 'Sedang berjalan',
                                'paused' => 'Dijeda',
                                'stopped' => 'Dihentikan',
                                'completed' => 'Selesai',
                                'completed_no_pending' => 'Selesai — tidak ada pending',
                                'failed' => 'Gagal',
                                'manual_auth_required' => 'Perlu login ulang',
                                default => str_replace('_', ' ', $status),
                            };
                        @endphp
                        <tr wire:key="automation-run-{{ $run->id }}">
                            <td><a class="font-mono" href="{{ route('automation.runs.show', $run) }}">{{ Str::limit($run->id, 18) }}</a></td>
                            <td><span class="badge {{ in_array($status, ['completed','running']) ? 'badge--success' : (in_array($status, ['failed','stopped']) ? 'badge--danger' : 'badge--warning') }}">{{ $runStatusLabel }}</span></td>
                            <td>{{ $run->pending_found }}</td>
                            <td>{{ $run->release_jobs_count }}</td>
                            <td>{{ $run->started_at?->diffForHumans() ?? '—' }}</td>
                            <td>
                                @can('automation.run')
                                    <div class="run-actions">
                                        @if ($status === 'running')
                                        <button type="button" wire:click="pause('{{ $run->id }}')" wire:loading.attr="disabled" wire:target="pause('{{ $run->id }}')" class="run-action-button run-action-button--pause">
                                            <span wire:loading.remove wire:target="pause('{{ $run->id }}')">Pause</span><span wire:loading wire:target="pause('{{ $run->id }}')">Memproses...</span>
                                        </button>
                                        @endif
                                        @if ($status === 'paused')
                                        <button type="button" wire:click="resume('{{ $run->id }}')" wire:loading.attr="disabled" wire:target="resume('{{ $run->id }}')" class="run-action-button run-action-button--resume">
                                            <span wire:loading.remove wire:target="resume('{{ $run->id }}')">Continue</span><span wire:loading wire:target="resume('{{ $run->id }}')">Melanjutkan...</span>
                                        </button>
                                        @endif
                                        @if (in_array($status, ['queued', 'running', 'paused'], true))
                                        <button type="button" wire:click="stop('{{ $run->id }}')" wire:loading.attr="disabled" wire:target="stop('{{ $run->id }}')" class="run-action-button run-action-button--stop">
                                            <span wire:loading.remove wire:target="stop('{{ $run->id }}')">Stop</span><span wire:loading wire:target="stop('{{ $run->id }}')">Menghentikan...</span>
                                        </button>
                                        @else
                                            <span class="text-muted">Tidak ada proses aktif</span>
                                        @endif
                                    </div>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-state">Belum ada automation run. Gunakan tombol “Ambil semua rilisan pending” untuk memulai.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="info-banner mt-5">
            <strong>Draft-only protection</strong>
            <span>Semua proses berhenti pada tahap draft. Review {{ $cmsName }} hanya berjalan setelah referensi draft SoundOn tersedia.</span>
        </div>

        @if($showWorkerOperationModal)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="worker-operation-title" wire:click.self="closeWorkerOperationModal">
                <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
                    <div class="flex items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-lg text-emerald-700">✓</span>
                        <div class="min-w-0 flex-1">
                            <h2 id="worker-operation-title" class="text-lg font-bold text-slate-900">{{ $workerOperationTitle }}</h2>
                            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $workerOperationMessage }}</p>
                        </div>
                        <button type="button" wire:click="closeWorkerOperationModal" class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700" aria-label="Tutup informasi">×</button>
                    </div>
                    <dl class="mt-5 grid grid-cols-3 gap-2 rounded-xl bg-slate-50 p-3 text-center text-[11px] text-slate-500">
                        <div><dt>Browser</dt><dd class="mt-1 font-semibold {{ $systemStatus['worker'] ? 'text-emerald-700' : 'text-red-600' }}">{{ $systemStatus['worker'] ? 'Online' : 'Offline' }}</dd></div>
                        <div><dt>Pipeline</dt><dd class="mt-1 font-semibold {{ $systemStatus['release_workers'] > 0 ? 'text-emerald-700' : 'text-slate-500' }}">{{ $systemStatus['release_workers'] > 0 ? 'Aktif' : 'Berhenti' }}</dd></div>
                        <div><dt>Checker</dt><dd class="mt-1 font-semibold {{ $systemStatus['status_workers'] > 0 ? 'text-emerald-700' : 'text-slate-500' }}">{{ $systemStatus['status_workers'] > 0 ? 'Aktif' : 'Berhenti' }}</dd></div>
                    </dl>
                    <div class="mt-5 flex justify-end">
                        <button type="button" wire:click="closeWorkerOperationModal" class="primary-button">Mengerti</button>
                    </div>
                </div>
            </div>
        @endif
    </main>
</div>
