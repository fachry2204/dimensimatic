<div wire:poll.2s="pollCollection">
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div><h1 class="page-title">Publish Rilis</h1><p class="page-subtitle">Ambil Under Review dari CMS, pilih satu atau semua. Draft SoundOn dicari berdasarkan judul; artis dicocokkan jika judul sama. Lalu Next sampai Submit. Setelah SoundOn berhasil, Upload Release dijalankan di {{ $cmsName }}.</p></div>
            <button wire:click="startCollection" wire:loading.attr="disabled" @disabled($collectionId !== null) class="primary-button"><span wire:loading.remove wire:target="startCollection">Ambil Semua Under Review CMS</span><span wire:loading wire:target="startCollection">Mengambil draft...</span></button>
        </section>
        <div wire:loading wire:target="startCollection" class="panel mb-5 p-4" role="status">Menghubungkan ke CMS, menyiapkan seluruh Under Review...</div>
        @if($collectionStatus !== 'idle')
            <section class="panel mb-5 p-4" role="status" aria-live="polite">
                <strong>{{ in_array($collectionStatus,['queued','running']) ? 'Sedang mengambil Under Review CMS' : ($collectionStatus === 'failed' ? 'Pengambilan belum lengkap' : 'Pengambilan selesai') }}</strong>
                <p>{{ $collectedDrafts }} / {{ $totalDrafts !== null ? $totalDrafts : 'total belum tersedia dari CMS' }} rilisan · Halaman {{ max(1,$collectionPage) }}</p>
                <p>{{ $collectionMessage }}</p>
                <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Pengambilan Under Review CMS" aria-valuemin="0" aria-valuemax="100" @if($totalDrafts !== null && $totalDrafts > 0) aria-valuenow="{{ min(100,(int)floor($collectedDrafts/$totalDrafts*100)) }}" @endif>
                    <div class="h-full rounded-full bg-indigo-500 {{ $collectionId ? 'animate-pulse' : '' }}" style="width: {{ $totalDrafts !== null && $totalDrafts > 0 ? min(100,(int)floor($collectedDrafts/$totalDrafts*100)) : ($collectionStatus === 'completed' ? 100 : 15) }}%"></div>
                </div>
            </section>
        @endif
        @if($message)<div class="panel mb-5 p-4" role="status">{{ \App\Support\SystemBranding::text($message) }}</div>@endif
        <div wire:loading wire:target="startCollection" class="panel p-5">Tabel dikosongkan. Mengambil daftar Under Review baru dari CMS...</div>
        <section wire:loading.remove wire:target="startCollection" class="panel overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 p-5">
                <span>{{ count($selectedIds) }} rilisan dipilih</span>
                <button wire:click="publishSelected" wire:loading.attr="disabled" @disabled(count($selectedIds)===0) class="primary-button">Publish Rilis Terpilih</button>
            </div>
            <div class="overflow-x-auto"><table class="data-table"><thead><tr>
                <th><input type="checkbox" @checked(count($selectedIds)>0 && count($selectedIds)===$items->filter(fn($row)=>in_array($row->status,['draft','failed']) && $row->cms_release_url)->count()) wire:click="toggleAll" aria-label="Pilih semua draft siap publish"></th><th>Rilisan / Artis</th><th>SoundOn</th><th>{{ $cmsName }}</th><th>Proses</th>
            </tr></thead><tbody>
            @forelse($items as $item)
                <tr wire:key="publish-{{ $item->id }}">
                    <td><input type="checkbox" wire:model.live="selectedIds" value="{{ $item->id }}" @disabled(!in_array($item->status,['draft','failed']) || !$item->cms_release_url) aria-label="Pilih {{ $item->title }}"></td>
                    <td><strong>{{ $item->title }}</strong><p>{{ $item->artist }}</p></td>
                    <td><a href="{{ $item->draft_url }}" target="_blank" rel="noopener">{{ $item->soundon_published_at ? 'Publish berhasil' : 'Dicari saat publish' }} ↗</a></td>
                    <td>@if($item->cms_release_url)<a href="{{ $item->cms_release_url }}" target="_blank" rel="noopener">{{ $item->cms_uploaded_at ? 'Uploading' : 'Under Review' }} ↗</a>@else<span>Pasangan Under Review belum ditemukan / ambigu</span>@endif</td>
                    <td>{{ ['draft'=>'Siap dipilih','queued'=>'Menunggu antrean','publishing'=>'Publish SoundOn berjalan','updating_cms'=>'Upload Release CMS berjalan','success'=>'Selesai','failed'=>'Gagal — pilih untuk mencoba ulang','unavailable'=>'Tidak lagi tersedia di draft','blocked'=>'Perlu pemeriksaan draft'][$item->status] ?? $item->status }}
                    @php
                        $percent = $item->status === 'success' ? 100 : max(0, min(100, (int) $item->progress_percent));
                        $active = in_array($item->status, ['queued','publishing','updating_cms']);
                        $barColor = $item->status === 'failed' ? 'bg-red-500' : ($item->status === 'success' ? 'bg-emerald-500' : 'bg-indigo-500');
                        $stage = $item->progress_message ?: ($active ? 'Menunggu progres proses' : 'Belum diproses');
                    @endphp
                    <div class="mt-2 min-w-64 max-w-96" aria-live="polite">
                        <div class="mb-1 flex items-center justify-between gap-3 text-xs">
                            <span>{{ \App\Support\SystemBranding::text($stage) }}</span>
                            <strong>{{ $percent }}%</strong>
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Progres publish {{ $item->title }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percent }}" aria-valuetext="{{ $percent }}% — {{ $stage }}">
                            <div class="h-full rounded-full transition-all duration-500 {{ $barColor }} {{ $active ? 'animate-pulse' : '' }}" style="width: {{ $percent }}%"></div>
                        </div>
                    </div>
                    @if($item->progress_attempt)<p class="mt-1 text-xs text-slate-500">Worker: {{ $item->worker_version ? 'v'.$item->worker_version : 'belum tercatat pada percobaan ini' }}</p>@endif
                    @if($item->error)<div class="max-w-96 whitespace-normal break-words text-red-600"><strong>Alasan gagal / perlu diperiksa:</strong><p>{{ \App\Support\SystemBranding::text($item->error) }}</p></div>@endif</td>
                </tr>
            @empty<tr><td colspan="5">{{ !$showDrafts ? 'Tabel telah dikosongkan. Daftar baru akan tampil setelah pengambilan seluruh Under Review selesai.' : 'Tekan Ambil Semua Under Review CMS untuk memulai.' }}</td></tr>@endforelse
            </tbody></table></div>
        </section>
    </main>
</div>
