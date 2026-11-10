<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading"><div><div class="eyebrow">Data transformation</div><h1 class="page-title">Metadata Mapping</h1><p class="page-subtitle">Hubungkan nilai metadata {{ $systemName }} ke format SoundOn. Nilai yang tidak dikenal tidak pernah ditebak otomatis.</p></div><span class="badge badge--brand">{{ $mappings->count() }} aturan</span></section>

        <section class="panel">
            <header class="panel-header"><div><h2 class="panel-title">Tambah aturan mapping</h2><p class="panel-copy">Buat pasangan nilai sumber dan target yang tervalidasi.</p></div></header>
            <form wire:submit="save" class="grid gap-4 p-5 md:grid-cols-4">
                <div><label class="form-label">Tipe metadata</label><select wire:model="mappingType" class="form-input"><option value="genre">Genre</option><option value="subgenre">Subgenre</option><option value="language">Language</option><option value="role">Role</option><option value="country">Country</option><option value="release_type">Release type</option></select></div>
                <div><label class="form-label">Nilai {{ $systemName }}</label><input wire:model="sourceValue" placeholder="Contoh: Pop Indonesia" class="form-input">@error('sourceValue')<p class="mt-1 text-xs text-red-600">{{ \App\Support\SystemBranding::text($message) }}</p>@enderror</div>
                <div><label class="form-label">Nilai SoundOn</label><input wire:model="targetValue" placeholder="Contoh: Pop" class="form-input"></div>
                <div class="flex items-end"><button class="primary-button w-full">Simpan mapping</button></div>
            </form>
        </section>

        <section class="panel mt-6">
            <header class="panel-header"><div><h2 class="panel-title">Daftar aturan</h2><p class="panel-copy">Mapping aktif diterapkan pada automation run berikutnya.</p></div></header>
            <div class="table-scroll"><table class="data-table"><thead><tr><th>Tipe</th><th>{{ $systemName }}</th><th>SoundOn</th><th>Status</th><th>Aksi</th></tr></thead><tbody>
            @forelse ($mappings as $mapping)
                <tr><td><span class="badge">{{ str_replace('_', ' ', $mapping->mapping_type) }}</span></td><td class="font-semibold text-slate-700">{{ $mapping->source_value }}</td><td class="font-semibold text-slate-700">{{ $mapping->target_value }}</td><td><span class="badge {{ $mapping->is_active ? 'badge--success' : '' }}">{{ $mapping->is_active ? 'Aktif' : 'Nonaktif' }}</span></td><td><button type="button" wire:click="toggle({{ $mapping->id }})" class="text-xs font-semibold text-indigo-700">{{ $mapping->is_active ? 'Nonaktifkan' : 'Aktifkan' }}</button></td></tr>
            @empty
                <tr><td colspan="5" class="empty-state">Belum ada mapping metadata.</td></tr>
            @endforelse
            </tbody></table></div>
        </section>
    </main>
</div>
