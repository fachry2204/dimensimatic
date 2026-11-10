<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div><div class="eyebrow">Field synchronization</div><h1 class="page-title">Sync Metadata</h1><p class="page-subtitle">Hubungkan setiap field {{ $systemName }} ke field SoundOn yang tepat. Halaman ini terpisah dari transformasi nilai genre, bahasa, dan role.</p></div>
            <div class="flex items-center gap-3"><span class="badge badge--success">{{ $activeCount }}/{{ $syncs->count() }} aktif</span><button wire:click="enableAll" class="primary-button">Aktifkan semua</button></div>
        </section>

        <div class="info-banner mb-6"><strong>Alur sinkronisasi</strong><span>{{ $systemName }} dibaca → nama field disinkronkan di halaman ini → nilai metadata ditransformasi melalui Metadata Mapping → draft SoundOn diisi.</span></div>

        @foreach (['release' => 'Metadata rilisan', 'track' => 'Metadata track & aset'] as $scope => $title)
            <section class="panel mb-6">
                <header class="panel-header"><div><h2 class="panel-title">{{ $title }}</h2><p class="panel-copy">Field sumber dan tujuan tidak akan tertukar dengan tipe metadata lain.</p></div><span class="badge badge--brand">{{ $syncs->where('scope', $scope)->count() }} field</span></header>
                <div class="table-scroll"><table class="data-table"><thead><tr><th>{{ $systemName }}</th><th>Arah</th><th>SoundOn</th><th>Key internal</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
                @foreach ($syncs->where('scope', $scope) as $sync)
                    <tr><td class="font-semibold text-slate-700">{{ $sync->source_label }}</td><td><span class="badge badge--brand">→ sync →</span></td><td class="font-semibold text-slate-700">{{ $sync->target_label }}</td><td class="font-mono text-xs text-slate-400">{{ $sync->source_field }} → {{ $sync->target_field }}</td><td><span class="badge {{ $sync->is_active ? 'badge--success' : 'badge--warning' }}">{{ $sync->is_active ? 'Aktif' : 'Nonaktif' }}</span></td><td><button wire:click="toggle({{ $sync->id }})" class="text-xs font-semibold {{ $sync->is_active ? 'text-red-600' : 'text-emerald-700' }}">{{ $sync->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></td></tr>
                @endforeach
                </tbody></table></div>
            </section>
        @endforeach

        <section class="panel mb-6">
            <header class="panel-header">
                <div><h2 class="panel-title">Perbaikan kompatibilitas nilai</h2><p class="panel-copy">Nilai {{ $systemName }} yang tidak tersedia persis di SoundOn dinormalisasi sebelum aset diunduh. Aturan baru disimpan otomatis dan digunakan kembali pada rilisan berikutnya.</p></div>
                <span class="badge badge--success">{{ $valueMappings->count() }} aturan aktif</span>
            </header>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Tipe</th><th>Nilai {{ $systemName }}</th><th>Arah</th><th>Nilai aman SoundOn</th><th>Status</th></tr></thead><tbody>
            @forelse ($valueMappings as $mapping)
                <tr><td><span class="badge">{{ ucfirst($mapping->mapping_type) }}</span></td><td class="font-semibold text-slate-700">{{ $mapping->source_value }}</td><td><span class="badge badge--brand">→ perbaiki →</span></td><td class="font-semibold text-slate-700">{{ filled($mapping->target_value) ? $mapping->target_value : 'Kosongkan (opsional)' }}</td><td><span class="badge badge--success">Aktif</span></td></tr>
            @empty
                <tr><td colspan="5" class="empty-state">Belum ada aturan perbaikan nilai.</td></tr>
            @endforelse
            </tbody></table></div>
        </section>
    </main>
</div>
