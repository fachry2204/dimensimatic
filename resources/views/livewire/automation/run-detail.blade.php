<div wire:poll.10s>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div><div class="eyebrow">Execution detail</div><h1 class="page-title">Automation Run</h1><p class="page-subtitle font-mono text-xs">{{ $run->id }}</p></div>
            @php($runStatus = $run->status->value)
            <span class="badge {{ in_array($runStatus, ['completed','running']) ? 'badge--success' : (in_array($runStatus, ['failed','stopped']) ? 'badge--danger' : 'badge--warning') }}">{{ str_replace('_', ' ', $runStatus) }}</span>
        </section>

        <section class="panel">
            <header class="panel-header"><div><h2 class="panel-title">Release jobs</h2><p class="panel-copy">Checkpoint dan hasil setiap rilisan dalam automation run ini.</p></div><span class="badge badge--brand">Live · 10 detik</span></header>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Release</th><th>Judul</th><th>Checkpoint</th><th>Status</th><th>Draft</th><th>Error</th><th>Aksi</th></tr></thead><tbody>
            @forelse ($jobs as $job)
                @php($jobStatus = $job->status->value)
                <tr><td class="font-mono">{{ $job->soundfresh_release_id }}</td><td class="font-semibold text-slate-700">{{ $job->release_title ?? '—' }}</td><td><span class="badge">{{ \App\Support\SystemBranding::text(str_replace('_', ' ', $job->checkpoint->value)) }}</span></td><td><span class="badge {{ in_array($jobStatus, ['completed','processing']) ? 'badge--success' : (in_array($jobStatus, ['failed','needs_attention']) ? 'badge--danger' : 'badge--warning') }}">{{ str_replace('_', ' ', $jobStatus) }}</span></td><td>@if ($job->soundon_draft_url)<a href="{{ $job->soundon_draft_url }}" target="_blank" rel="noopener noreferrer">Buka draft ↗</a>@else — @endif</td><td class="max-w-xs text-xs text-red-600">{{ \App\Support\SystemBranding::text($job->error_code) }} {{ \App\Support\SystemBranding::text($job->error_message) }}</td><td>@if (in_array($jobStatus, ['failed','needs_attention']))<button type="button" wire:click="retry('{{ $job->id }}')" class="secondary-button">Retry</button>@endif</td></tr>
            @empty
                <tr><td colspan="7" class="empty-state">Belum ada release job pada run ini.</td></tr>
            @endforelse
            </tbody></table></div>
        </section>

        <section class="panel mt-6">
            <header class="panel-header"><div><h2 class="panel-title">Metadata terintegrasi</h2><p class="panel-copy">Nilai hasil ekstraksi {{ $cmsName }} yang disiapkan untuk field SoundOn.</p></div></header>
            <div class="space-y-4 p-5">
                @forelse ($jobs->filter(fn ($item) => filled($item->metadata_snapshot_json)) as $metadataJob)
                    @php($metadata = $metadataJob->metadata_snapshot_json)
                    <details class="rounded-xl border border-slate-200 bg-slate-50" open>
                        <summary class="cursor-pointer px-4 py-3 text-sm font-bold text-slate-800">{{ $metadata['title'] ?? 'Rilisan #'.$metadataJob->soundfresh_release_id }} <span class="ml-2 text-xs font-normal text-slate-400">{{ $cmsName }} ID {{ $metadataJob->soundfresh_release_id }}</span></summary>
                        <div class="border-t border-slate-200 p-4">
                            <dl class="grid gap-3 text-xs sm:grid-cols-2 lg:grid-cols-4">
                                @foreach (['primary_artist' => 'Artis utama', 'release_type' => 'Tipe rilisan', 'version' => 'Versi', 'genre' => 'Genre', 'subgenre' => 'Subgenre', 'title_language' => 'Bahasa judul', 'release_date' => 'Tanggal rilis', 'upc' => 'UPC', 'record_label' => 'Label'] as $field => $label)
                                    <div><dt class="text-slate-400">{{ $label }}</dt><dd class="mt-1 font-semibold text-slate-700">{{ filled($metadata[$field] ?? null) ? $metadata[$field] : '—' }}</dd></div>
                                @endforeach
                            </dl>
                            <div class="mt-5 space-y-3">
                                @foreach ($metadata['tracks'] ?? [] as $track)
                                    <article class="rounded-lg border border-slate-200 bg-white p-4"><div class="flex flex-wrap items-center justify-between gap-2"><strong class="text-sm text-slate-800">Track {{ $track['position'] ?? $loop->iteration }} · {{ $track['title'] ?? 'Tanpa judul' }}</strong><span class="badge {{ !empty($track['audio']['local_path']) ? 'badge--success' : 'badge--warning' }}">Audio {{ !empty($track['audio']['local_path']) ? 'siap upload' : 'belum diproses' }}</span></div><div class="mt-3 grid gap-2 text-xs sm:grid-cols-2 lg:grid-cols-4"><span><b>Artis:</b> {{ $track['primary_artist'] ?? '—' }}</span><span><b>ISRC:</b> {{ $track['isrc'] ?? '—' }}</span><span><b>Songwriter:</b> {{ $track['songwriters'] ?? '—' }}</span><span><b>Contributor:</b> {{ $track['contributors'] ?? '—' }}</span><span><b>Production:</b> {{ $track['production_contributors'] ?? '—' }}</span><span><b>Explicit:</b> {{ !empty($track['explicit']) ? 'Ya' : 'Tidak' }}</span><span><b>Instrumental:</b> {{ !empty($track['instrumental']) ? 'Ya' : 'Tidak' }}</span><span><b>Bahasa:</b> {{ $track['language'] ?? '—' }}</span></div></article>
                                @endforeach
                            </div>
                        </div>
                    </details>
                @empty
                    <div class="empty-state">Metadata belum berhasil diekstrak. Jalankan atau retry job untuk memproses dan menyimpannya sebagai draft SoundOn.</div>
                @endforelse
            </div>
        </section>

        <section class="panel mt-6">
            <header class="panel-header"><div><h2 class="panel-title">Audit timeline</h2><p class="panel-copy">Jejak aktivitas terbaru untuk kebutuhan monitoring dan investigasi.</p></div></header>
            <div class="divide-y divide-slate-100 px-5">
                @forelse ($events as $event)
                    <article class="relative py-4 pl-7 before:absolute before:left-0 before:top-5 before:h-2.5 before:w-2.5 before:rounded-full before:bg-indigo-500"><div class="flex flex-wrap items-center gap-2"><strong class="text-sm text-slate-800">{{ \App\Support\SystemBranding::text(str_replace('_', ' ', $event->event)) }}</strong><time class="text-xs text-slate-400">{{ $event->created_at }}</time></div><p class="mt-1 text-sm text-slate-600">{{ \App\Support\SystemBranding::text($event->message) }}</p></article>
                @empty
                    <div class="empty-state">Belum ada audit event.</div>
                @endforelse
            </div>
        </section>
    </main>
</div>
