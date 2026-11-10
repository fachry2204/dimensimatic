<div wire:poll.3s.keep-alive="pollStatusUpdates">
    <x-automation-nav />

    <main class="page-shell">
        <section class="page-heading">
            <div>
                <div class="eyebrow">Release tracking</div>
                <h1 class="page-title">Cek Status Rilis</h1>
                <p class="page-subtitle">Pantau pemeriksaan setiap rilisan {{ $cmsName }} terhadap status terbaru di SoundOn.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <span class="badge badge--brand">Live · 3 detik</span>
                <span class="text-xs text-slate-500">Sumber: seluruh Uploading {{ $cmsName }}</span>
                <button type="button" wire:click="downloadUnderReviewReport" wire:loading.attr="disabled" wire:target="downloadUnderReviewReport" class="secondary-button">
                    <span wire:loading.remove wire:target="downloadUnderReviewReport">Download Rilis Under Review</span>
                    <span wire:loading wire:target="downloadUnderReviewReport">Membuat Excel...</span>
                </button>
                <button type="button" wire:click="refreshSoundOnStatuses" wire:loading.attr="disabled" wire:target="refreshSoundOnStatuses" @disabled($bulkCheckRequested) class="primary-button">
                    <span wire:loading.remove wire:target="refreshSoundOnStatuses">{{ $bulkCheckRequested ? $scanLabel : 'Cek Status SoundOn' }}</span>
                    <span wire:loading wire:target="refreshSoundOnStatuses">Mengosongkan tabel dan memulai pemeriksaan...</span>
                </button>
            </div>
        </section>

        @if($scanActive)
            <div class="mt-4 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3" role="status" aria-live="polite">
                <p class="text-sm font-semibold text-indigo-700">{{ $scanLabel }}</p>
                <p class="mt-1 text-xs text-indigo-600">Pemeriksaan dimulai {{ $scanRun->created_at?->diffForHumans() }}. {{ $scanFinished }} / {{ $scanTotal }} rilisan selesai. Tabel menampilkan pemeriksaan terbaru dan diperbarui setiap 3 detik.</p>
                @if($scanTotal > 0)
                    <progress class="mt-2 w-full" max="{{ $scanTotal }}" value="{{ $scanFinished }}" aria-label="Progres pemeriksaan SoundOn"></progress>
                @elseif($scanRun->status->value === 'queued' && $scanRun->created_at?->lt(now()->subSeconds(30)))
                    <p class="mt-2 text-xs text-amber-700">Antrean belum diambil worker. Periksa layanan antrean status-checks pada aplikasi.</p>
                @else
                    <p class="mt-2 text-xs text-indigo-600">Tabel sebelumnya sudah dikosongkan. Menunggu daftar Uploading terbaru.</p>
                @endif
            </div>
        @endif

        <section class="metric-grid" wire:loading.remove wire:target="refreshSoundOnStatuses">
            <button type="button" wire:click="filterBySoundOnStatus('all')" class="metric-card metric-card--filter {{ $soundOnStatus === 'all' ? 'metric-card--active' : '' }}" style="--metric-soft:#eef2ff" aria-pressed="{{ $soundOnStatus === 'all' ? 'true' : 'false' }}"><span class="metric-label">Seluruh rilis diambil</span><span class="metric-value">{{ $totalJobs }}</span><span class="metric-meta">Klik untuk menampilkan seluruh status rilisan</span></button>
            <button type="button" wire:click="filterBySoundOnStatus('under_review')" class="metric-card metric-card--filter {{ $soundOnStatus === 'under_review' ? 'metric-card--active' : '' }}" style="--metric-soft:#eeeeff" aria-pressed="{{ $soundOnStatus === 'under_review' ? 'true' : 'false' }}"><span class="metric-label">Under Review</span><span class="metric-value">{{ $underReviewJobs }}</span><span class="metric-meta">Klik untuk menampilkan rilisan Under Review</span></button>
            <button type="button" wire:click="filterByReleaseDate('today')" class="metric-card metric-card--filter {{ $releaseDateFilter === 'today' ? 'metric-card--active' : '' }}" style="--metric-soft:#e8f8f2" aria-pressed="{{ $releaseDateFilter === 'today' ? 'true' : 'false' }}"><span class="metric-label">Rilis Hari Ini · Under Review</span><span class="metric-value" style="color:#087f5b">{{ $todayReviewJobs }}</span><span class="metric-meta">Klik untuk menampilkan rilis hari ini yang masih Under Review</span></button>
            <button type="button" wire:click="filterByReleaseDate('overdue')" class="metric-card metric-card--filter {{ $releaseDateFilter === 'overdue' ? 'metric-card--active' : '' }}" style="--metric-soft:#fff0f2" aria-pressed="{{ $releaseDateFilter === 'overdue' ? 'true' : 'false' }}"><span class="metric-label">Rilis Terlewat · Under Review</span><span class="metric-value" style="color:#d6334c">{{ $overdueReviewJobs }}</span><span class="metric-meta">Klik untuk menampilkan rilis terlewat yang masih Under Review</span></button>
            <button type="button" wire:click="filterBySoundOnStatus('delivery')" class="metric-card metric-card--filter {{ $soundOnStatus === 'delivery' ? 'metric-card--active' : '' }}" style="--metric-soft:#e8f8f2" aria-pressed="{{ $soundOnStatus === 'delivery' ? 'true' : 'false' }}"><span class="metric-label">Delivered</span><span class="metric-value" style="color:#087f5b">{{ $deliveredJobs }}</span><span class="metric-meta">Klik untuk menampilkan rilisan Delivered</span></button>
            <button type="button" wire:click="filterBySoundOnStatus('approved')" class="metric-card metric-card--filter {{ $soundOnStatus === 'approved' ? 'metric-card--active' : '' }}" style="--metric-soft:#edf4ff" aria-pressed="{{ $soundOnStatus === 'approved' ? 'true' : 'false' }}"><span class="metric-label">Approved</span><span class="metric-value" style="color:#3159bd">{{ $approvedJobs }}</span><span class="metric-meta">Klik untuk menampilkan rilisan Approved</span></button>
            <button type="button" wire:click="filterBySoundOnStatus('live')" class="metric-card metric-card--filter {{ $soundOnStatus === 'live' ? 'metric-card--active' : '' }}" style="--metric-soft:#e8f8f2" aria-pressed="{{ $soundOnStatus === 'live' ? 'true' : 'false' }}"><span class="metric-label">Live</span><span class="metric-value" style="color:#087f5b">{{ $liveJobs }}</span><span class="metric-meta">Klik untuk menampilkan rilisan Live</span></button>
            <button type="button" wire:click="filterBySoundOnStatus('not_approved')" class="metric-card metric-card--filter {{ $soundOnStatus === 'not_approved' ? 'metric-card--active' : '' }}" style="--metric-soft:#fff0f2" aria-pressed="{{ $soundOnStatus === 'not_approved' ? 'true' : 'false' }}"><span class="metric-label">Not Approved</span><span class="metric-value" style="color:#d6334c">{{ $notApprovedJobs }}</span><span class="metric-meta">Klik untuk menampilkan rilisan Not Approved</span></button>
        </section>

        <section class="panel mt-6">
            <header class="panel-header release-status-toolbar">
                <div class="flex-1">
                    <h2 class="panel-title">Daftar status rilisan</h2>
                    <p class="panel-copy">Tabel menampilkan hasil pemeriksaan terbaru. Status dan progres setiap rilisan diperbarui otomatis.</p>
                </div>
            </header>
            
            <div class="border-b border-slate-100 bg-slate-50/50 px-5 py-4">
                <div class="release-status-filters">
                    <label class="release-filter release-filter--search"><span>Cari rilisan</span><input wire:model.live.debounce.400ms="search" type="search" placeholder="Judul, artis, ID {{ $cmsName }} atau draft"></label>
                    <label class="release-filter"><span>Status pemeriksaan</span><select wire:model.live="status"><option value="all">Semua status</option><option value="queued">Menunggu</option><option value="checking">Sedang diperiksa</option><option value="detected">Terdeteksi</option><option value="not_found">Tidak ditemukan</option><option value="failed">Gagal</option></select></label>
                    <label class="release-filter"><span>Status SoundOn</span><select wire:model.live="soundOnStatus"><option value="new">Status Baru Diambil</option><option value="all">Semua</option><option value="under_review">Under Review</option><option value="delivery">Delivered</option><option value="approved">Approved</option><option value="not_approved">Not Approved</option><option value="live">Live</option><option value="pending">Belum terdeteksi</option></select></label>
                    <button type="button" wire:click="resetFilters" class="secondary-button" style="height: 40px; margin-bottom: 2px;">Reset</button>
                </div>
            </div>

            @if ($syncMessage)<div class="mx-5 mt-4 rounded-lg bg-slate-50 px-4 py-3 text-xs text-slate-600">{{ $syncMessage }} @if (str_contains($syncMessage, 'Pengaturan Platform'))<a href="{{ route('automation.sessions') }}" class="font-semibold underline">Buka Pengaturan Platform</a>@endif</div>@endif

            @if ($verificationTotal > 0 && ($verificationSending > 0 || $verificationProgress < 100))
                <div class="mx-5 mt-4 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3" role="status" aria-live="polite">
                    <div class="flex items-center justify-between gap-3 text-xs font-semibold text-indigo-700">
                        <span>Mengubah UPC dan ISRC di {{ $cmsName }}{{ $verificationCurrentTitle ? ': '.$verificationCurrentTitle : '' }}</span>
                        <span>{{ $verificationSucceeded + $verificationFailed }} / {{ $verificationTotal }} selesai</span>
                    </div>
                    <div class="mt-2 h-2 overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-label="Progress pengiriman UPC dan ISRC ke {{ $cmsName }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $verificationProgress }}">
                        <div class="h-full rounded-full bg-indigo-600 transition-all duration-500 {{ $verificationSending > 0 ? 'animate-pulse' : '' }}" style="width: {{ max(5, $verificationProgress) }}%"></div>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center justify-between gap-3">
                        <p class="text-[11px] text-indigo-600">Jangan tutup halaman saat proses berjalan. Halaman diperbarui otomatis setiap 3 detik.</p>
                        <button type="button" wire:click="stopVerificationUpdates" wire:loading.attr="disabled" wire:target="stopVerificationUpdates" class="secondary-button release-status-action release-status-action--failed">
                            <span wire:loading.remove wire:target="stopVerificationUpdates">Stop</span>
                            <span wire:loading wire:target="stopVerificationUpdates">Menghentikan...</span>
                        </button>
                    </div>
                </div>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-slate-100 px-5 py-4">
                <div>
                    <span class="text-xs font-semibold text-slate-500">{{ count($selectedJobIds) }} rilisan dipilih</span>
                    @if(count($selectedJobIds) === 0)
                        <p class="mt-1 text-[11px] text-slate-400">Centang rilisan Uploading yang UPC dan seluruh ISRC-nya sudah tersedia.</p>
                    @endif
                </div>
                <button type="button" wire:click="verifySelected" wire:loading.attr="disabled" wire:target="verifySelected" class="primary-button" @disabled(count($selectedJobIds) === 0)>
                    <span wire:loading.remove wire:target="verifySelected">Ubah UPC dan ISRC ke {{ $cmsName }}</span>
                    <span wire:loading wire:target="verifySelected">Mengantrekan Verify Release...</span>
                </button>
            </div>

            <div wire:loading wire:target="refreshSoundOnStatuses" class="empty-state" role="status">Mengosongkan tabel sebelumnya dan menyiapkan pemeriksaan terbaru...</div>
            <div class="table-scroll" wire:loading.remove wire:target="refreshSoundOnStatuses">
                <table class="data-table release-status-table">
                    <thead><tr><th><input type="checkbox" wire:click="toggleAllVisible({{ Js::from($selectableJobIds) }})" @checked(count($selectableJobIds) > 0 && count(array_intersect($selectableJobIds, $selectedJobIds)) === count($selectableJobIds)) aria-label="Pilih semua rilisan yang siap diverifikasi"></th><th>Rilisan</th><th>{{ $cmsName }}</th><th>SoundOn</th><th>UPC</th><th>ISRC</th><th>Status pemeriksaan</th><th>Aksi</th></tr></thead>
                    <tbody>
                    @forelse ($jobs as $job)
                        @php
                            $title = filled($job->release_title) && strtolower($job->release_title) !== 'view' ? $job->release_title : 'Rilisan #'.$job->soundfresh_release_id;
                            $soundOnLabels = ['under_review' => 'Under Review', 'delivery' => 'Delivered', 'approved' => 'Approved', 'not_approved' => 'Not Approved', 'live' => 'Live'];
                            $soundOnStatus = in_array($job->soundon_check_status, ['queued', 'checking'], true) ? null : $job->soundon_release_status;
                            $identifierVisible = in_array($soundOnStatus, ['delivery', 'approved', 'live'], true);
                            $identifiersComplete = filled($job->soundon_upc) && count(array_filter($job->soundon_isrcs_json ?? [])) >= max(1, (int) $job->track_count);
                            $soundOnClasses = ['under_review' => 'badge--soundon-review', 'delivery' => 'badge--soundon-delivered', 'approved' => 'badge--soundon-approved', 'not_approved' => 'badge--soundon-rejected', 'live' => 'badge--soundon-live'];
                            $checkStatus = $job->soundon_check_status;
                            $checkLabels = ['queued' => 'Menunggu', 'checking' => 'Sedang diperiksa', 'detected' => 'Terdeteksi', 'not_found' => 'Tidak ditemukan', 'failed' => 'Gagal', 'stopped' => 'Dihentikan'];
                            $checkClass = $checkStatus === 'detected' ? 'badge--success' : (in_array($checkStatus, ['failed', 'stopped'], true) ? 'badge--danger' : 'badge--warning');
                            $verifyStatus = $job->soundfresh_verify_status;
                            $canVerify = $job->cmsVerificationIdentifiers() !== null
                                && !in_array($verifyStatus, ['success', 'sending', 'rejecting'], true);
                            $releaseDateRaw = data_get($job->metadata_snapshot_json, 'release_date');
                            $releaseDate = null;
                            $releaseTimezone = (string) config('automation.release_timezone', 'Asia/Bangkok');
                            if (filled($releaseDateRaw)) {
                                try {
                                    $releaseDate = \Carbon\CarbonImmutable::parse($releaseDateRaw, $releaseTimezone)->startOfDay();
                                } catch (\Throwable) {
                                    $releaseDate = null;
                                }
                            }
                            $releaseDayDistance = $releaseDate?->diffInDays(
                                \Carbon\CarbonImmutable::now($releaseTimezone)->startOfDay(),
                                false,
                            );
                        @endphp
                        <tr wire:key="release-status-{{ $job->id }}">
                            <td><input type="checkbox" wire:model.live="selectedJobIds" value="{{ $job->id }}" @disabled(!$canVerify) aria-label="Pilih {{ $title }}"></td>
                            <td><div class="min-w-52"><strong class="text-slate-800">{{ $title }}</strong><p class="mt-1 text-[11px] text-slate-400">{{ $job->artist_name ?: 'Artis tidak tersedia' }}</p></div></td>
                            @php($soundfreshStatusLabels = ['pending' => 'Pending', 'revision' => 'Revision', 'under_review' => 'Under Review', 'uploading' => 'Uploading', 'rejected' => 'Rejected', 'published' => 'Published', 'draft' => 'Draft', 'verified' => 'Verified'])
                            <td>
                                <a href="{{ $job->soundfresh_release_url }}" target="_blank" rel="noopener noreferrer" class="badge badge--success">ID {{ $job->soundfresh_release_id }} ↗</a>
                                <p class="mt-1 text-[11px] text-slate-400">Tab: {{ $soundfreshStatusLabels[$job->soundfresh_workflow_status] ?? ucfirst(str_replace('_', ' ', $job->soundfresh_workflow_status ?? 'belum diketahui')) }}</p>
                                <p class="mt-1 text-[11px] font-medium text-slate-600">Tanggal rilis: {{ $releaseDate?->translatedFormat('d M Y') ?? 'Belum tersedia' }}</p>
                            </td>
                            <td>
                                @if ($soundOnStatus)
                                    <a @if($job->soundon_draft_url) href="{{ $job->soundon_draft_url }}" target="_blank" rel="noopener noreferrer" @endif class="badge {{ $soundOnClasses[$soundOnStatus] ?? 'badge--brand' }}">{{ $soundOnLabels[$soundOnStatus] ?? ucfirst(str_replace('_', ' ', $soundOnStatus)) }} @if($job->soundon_draft_url)↗@endif</a>
                                    <p class="mt-1 text-[11px] text-slate-400">Dicek {{ $job->soundon_status_checked_at?->diffForHumans() ?? '—' }}</p>
                                    @if($releaseDate && $releaseDayDistance > 0)
                                        <p class="mt-1 text-[11px] font-semibold text-red-600">Rilis Terlewat · {{ $releaseDayDistance }} hari</p>
                                    @elseif($releaseDate && $releaseDayDistance < 0)
                                        <p class="mt-1 text-[11px] font-semibold text-indigo-600">{{ abs($releaseDayDistance) }} hari lagi menuju tanggal rilis</p>
                                    @elseif($releaseDate)
                                        <p class="mt-1 text-[11px] font-semibold text-emerald-600">Rilis hari ini</p>
                                    @endif
                                    @if($soundOnStatus === 'not_approved')
                                        <p class="mt-2 max-w-64 text-[11px] font-medium text-red-600" title="{{ $job->soundon_rejection_reason }}">Alasan: {{ $job->soundon_rejection_reason ?: 'Belum terbaca; cek status kembali.' }}</p>
                                    @endif
                                @else
                                    <span class="badge badge--warning">Belum terdeteksi</span><p class="mt-1 text-[11px] text-slate-400">{{ $checkStatus === 'checking' ? 'Pemeriksaan terbaru sedang berjalan' : ($checkStatus === 'queued' ? 'Menunggu giliran pemeriksaan terbaru' : ($job->error_code ?: 'Menunggu pemeriksaan SoundOn')) }}</p>
                                    @if($job->soundon_draft_id)<p class="mt-1 text-[11px] text-slate-400">ID {{ $job->soundon_draft_id }}</p>@endif
                                @endif
                            </td>
                            <td><span class="font-mono text-xs text-slate-700">{{ $identifierVisible ? ($job->soundon_upc ?: '—') : '—' }}</span></td>
                            <td>@if($identifierVisible && count($job->soundon_isrcs_json ?? []))<div class="grid gap-1">@foreach($job->soundon_isrcs_json as $isrc)<span class="font-mono text-xs text-slate-700">{{ $isrc }}</span>@endforeach</div>@else — @endif</td>
                            <td>
                                <span class="badge {{ $checkClass }}">{{ $checkLabels[$checkStatus] ?? 'Belum diperiksa' }}</span>
                                @if(in_array($checkStatus, ['queued', 'checking'], true))
                                    <div class="mt-2 h-2 w-40 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Progress pemeriksaan {{ $title }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $job->soundon_check_progress }}">
                                        <div class="h-full rounded-full bg-indigo-500 transition-all duration-500 {{ $checkStatus === 'checking' ? 'animate-pulse' : '' }}" style="width: {{ max(5, (int) $job->soundon_check_progress) }}%"></div>
                                    </div>
                                    <p class="mt-1 text-[11px] text-slate-400">{{ $checkStatus === 'checking' ? 'Mencari '.$title.' di SoundOn' : 'Menunggu giliran pemeriksaan' }}</p>
                                @elseif($job->soundon_check_error)
                                    <p class="mt-1 max-w-64 truncate text-[11px] text-red-500" title="{{ $job->soundon_check_error }}">{{ $job->soundon_check_error }}</p>
                                @endif
                                @if($verifyStatus === 'success')
                                    <p class="mt-2 text-xs text-emerald-700">CMS: Verify Release berhasil</p>
                                @elseif($verifyStatus === 'sending')
                                    <p class="mt-2 text-xs text-indigo-600">CMS: antrean pengiriman / Verify Release berjalan</p>
                                @elseif($verifyStatus === 'failed')
                                    <p class="mt-2 text-xs text-red-600">CMS: pengiriman gagal</p>
                                    <p class="mt-1 max-w-64 whitespace-normal break-words text-xs text-red-600">{{ \App\Support\SystemBranding::text($job->soundfresh_verify_error) }}</p>
                                @elseif($canVerify)
                                    <p class="mt-2 text-xs text-slate-600">CMS: menunggu pengiriman otomatis</p>
                                @endif
                                <p class="mt-2 text-[11px] text-slate-400">Diperbarui {{ $job->updated_at?->diffForHumans() ?? '—' }}</p>
                            </td>
                            <td>
                                <div class="release-status-actions">
                                    <button type="button" wire:click="checkSoundOnStatus('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="checkSoundOnStatus('{{ $job->id }}')" class="secondary-button release-status-action release-status-action--check" @disabled(in_array($checkStatus, ['queued', 'checking'], true))>
                                        <span wire:loading.remove wire:target="checkSoundOnStatus('{{ $job->id }}')">{{ $checkStatus === 'checking' ? 'Sedang diperiksa...' : ($checkStatus === 'queued' ? 'Menunggu pemeriksaan...' : 'Cek Status SoundOn') }}</span>
                                        <span wire:loading wire:target="checkSoundOnStatus('{{ $job->id }}')">Mengantrekan...</span>
                                    </button>
                                    @if($soundOnStatus === 'not_approved' && $verifyStatus === 'rejected')
                                        <button type="button" class="secondary-button release-status-action release-status-action--success" disabled aria-disabled="true">Rejected di {{ $cmsName }}</button>
                                    @elseif($soundOnStatus === 'not_approved' && $verifyStatus === 'rejecting')
                                        <button type="button" class="secondary-button release-status-action release-status-action--disabled" disabled aria-disabled="true">Mengirim Reject...</button>
                                    @elseif($soundOnStatus === 'not_approved' && $verifyStatus === 'failed')
                                        <button type="button" wire:click="openRejectInSoundfresh('{{ $job->id }}')" class="secondary-button release-status-action release-status-action--failed" title="{{ \App\Support\SystemBranding::text($job->soundfresh_verify_error) }}">
                                            Gagal
                                        </button>
                                    @elseif($soundOnStatus === 'not_approved' && filled($job->soundon_rejection_reason))
                                        <button type="button" wire:click="openRejectInSoundfresh('{{ $job->id }}')" class="secondary-button release-status-action release-status-action--failed" title="Masukkan pesan penolakan sebelum dikirim ke {{ $cmsName }}">
                                            Reject di {{ $cmsName }}
                                        </button>
                                    @elseif($soundOnStatus === 'not_approved')
                                        <button type="button" class="secondary-button release-status-action release-status-action--disabled" disabled aria-disabled="true" title="Cek status kembali untuk mengambil alasan penolakan">Alasan belum tersedia</button>
                                    @elseif($verifyStatus === 'sending')
                                        <button type="button" class="secondary-button release-status-action release-status-action--disabled" disabled aria-disabled="true" title="Pengiriman UPC dan ISRC ke {{ $cmsName }} sedang berjalan">Mengubah UPC &amp; ISRC...</button>
                                        <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-indigo-100" role="progressbar" aria-label="Mengubah UPC dan ISRC {{ $title }} di {{ $cmsName }}">
                                            <div class="h-full w-2/3 animate-pulse rounded-full bg-indigo-500"></div>
                                        </div>
                                    @elseif($verifyStatus === 'success')
                                        <button type="button" class="secondary-button release-status-action release-status-action--success" disabled aria-disabled="true" title="UPC dan ISRC berhasil dikirim dan diverifikasi di {{ $cmsName }}">Berhasil Dikirim</button>
                                    @elseif($verifyStatus === 'failed')
                                        <button type="button" wire:click="sendToSoundfresh('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="sendToSoundfresh('{{ $job->id }}')" class="secondary-button release-status-action release-status-action--failed" title="{{ \App\Support\SystemBranding::text($job->soundfresh_verify_error) }}">
                                            <span wire:loading.remove wire:target="sendToSoundfresh('{{ $job->id }}')">Gagal</span>
                                            <span wire:loading wire:target="sendToSoundfresh('{{ $job->id }}')">Mengirim...</span>
                                        </button>
                                    @elseif($canVerify)
                                        <button type="button" wire:click="sendToSoundfresh('{{ $job->id }}')" wire:loading.attr="disabled" wire:target="sendToSoundfresh('{{ $job->id }}')" class="secondary-button release-status-action">
                                            <span wire:loading.remove wire:target="sendToSoundfresh('{{ $job->id }}')">Ubah UPC &amp; ISRC</span>
                                            <span wire:loading wire:target="sendToSoundfresh('{{ $job->id }}')">Mengirim...</span>
                                        </button>
                                    @else
                                        <button type="button" class="secondary-button release-status-action release-status-action--disabled" disabled aria-disabled="true" title="Tombol aktif untuk rilisan Uploading setelah UPC dan seluruh ISRC ditemukan">Ubah UPC &amp; ISRC</button>
                                    @endif
                                    @if($verifyStatus === 'failed' && $job->soundfresh_verify_error)<p class="mt-1 max-w-52 text-[10px] text-red-500" title="{{ \App\Support\SystemBranding::text($job->soundfresh_verify_error) }}">Klik Gagal untuk mencoba kembali</p>@endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty-state">Tidak ada rilisan yang cocok dengan pencarian atau filter.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if($rejectingJobId)
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4" role="dialog" aria-modal="true" aria-labelledby="reject-title">
                <div class="w-full max-w-xl rounded-2xl bg-white p-6 shadow-2xl">
                    <h2 id="reject-title" class="text-lg font-bold text-slate-900">Pesan penolakan untuk {{ $cmsName }}</h2>
                    <p class="mt-2 text-sm text-slate-600">Pesan ini akan diisi pada dialog Reject Release setelah sistem membuka panah di samping tombol Verify Release di {{ $cmsName }}.</p>
                    <label class="mt-4 block text-sm font-semibold text-slate-700" for="reject-reason">Alasan penolakan</label>
                    <textarea id="reject-reason" wire:model="rejectReason" rows="6" class="mt-2 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none" placeholder="Tulis alasan penolakan untuk dikirim ke {{ $cmsName }}"></textarea>
                    @error('rejectReason') <p class="mt-1 text-xs text-red-600">{{ \App\Support\SystemBranding::text($message) }}</p> @enderror
                    <div class="mt-5 flex justify-end gap-3">
                        <button type="button" wire:click="cancelRejectInSoundfresh" class="secondary-button">Batal</button>
                        <button type="button" wire:click="rejectInSoundfresh" wire:loading.attr="disabled" wire:target="rejectInSoundfresh" class="secondary-button release-status-action--failed">
                            <span wire:loading.remove wire:target="rejectInSoundfresh">Kirim Reject ke {{ $cmsName }}</span>
                            <span wire:loading wire:target="rejectInSoundfresh">Mengirim Reject...</span>
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </main>
</div>
