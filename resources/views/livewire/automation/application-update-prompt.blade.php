<div wire:init="refreshNotification" wire:poll.30s="refreshNotification">
    @if($showModal)
        <div class="update-confirmation-overlay" x-data="{ busy: false, previous: document.activeElement, background: [], init() { this.background = [...document.querySelectorAll('main,.workspace-sidebar,.workspace-topbar')].map(el => [el, el.inert]); this.background.forEach(([el]) => el.inert = true); }, destroy() { this.background.forEach(([el, value]) => el.inert = value); this.previous?.focus(); } }" x-on:keydown.escape.window="if (!busy) $wire.dismiss()">
            <section class="update-confirmation-dialog" role="dialog" aria-modal="true" aria-labelledby="update-confirmation-title" tabindex="-1"
                x-init="$nextTick(() => $el.focus())"
                x-on:keydown.tab="const nodes = [...$el.querySelectorAll('button:not([disabled])')]; if (!nodes.length) { $event.preventDefault(); } else if ($event.shiftKey && ($event.target === nodes[0] || $event.target === $el)) { $event.preventDefault(); nodes.at(-1).focus(); } else if (!$event.shiftKey && ($event.target === nodes.at(-1) || $event.target === $el)) { $event.preventDefault(); nodes[0].focus(); }">
                <div class="eyebrow">Pembaruan DimensiMatic</div>
                <h2 id="update-confirmation-title">{{ $downloadReady ? 'Paket update siap' : ($updateAvailable ? 'Update v'.$latestVersion.' tersedia' : 'Pemeriksaan update') }}</h2>
                @if($updateAvailable && !$downloadReady)
                    <p>Apakah Anda ingin memperbarui sistem dan <strong>menghentikan semua pekerjaan serta seluruh worker</strong> yang sedang berjalan?</p>
                    <p class="update-confirmation-muted">Paket diunduh dan diperiksa dahulu. Setelah itu worker dihentikan, antrean aktif dibatalkan, jadwal otomatis dinonaktifkan, dan database dicadangkan sebelum installer dijalankan.</p>
                    <p class="update-confirmation-muted">Data rilisan, pengaturan, dan sesi login dipertahankan. Periksa pekerjaan yang dihentikan sebelum melanjutkannya setelah update.</p>
                @endif
                @if($message && (!$updateAvailable || $downloadReady || !str_starts_with($message, 'Update tersedia:')))
                    <p class="update-confirmation-message" role="status">{{ \App\Support\SystemBranding::text($message) }}</p>
                @endif
                <div wire:loading wire:target="downloadAndInstall" class="update-confirmation-progress" role="status">Mengunduh, memverifikasi, lalu menghentikan pekerjaan dan menyiapkan update…<span></span></div>
                <footer class="update-confirmation-actions">
                    <button type="button" class="secondary-button" wire:click="dismiss" wire:loading.attr="disabled" wire:target="downloadAndInstall" @disabled($installStarted)>{{ $downloadReady || !$updateAvailable ? 'Tutup' : 'Nanti' }}</button>
                    @if($updateAvailable && !$downloadReady)
                        <button type="button" class="primary-button" x-on:click="busy = true; $wire.downloadAndInstall(true, !!window.chrome?.webview).finally(() => busy = false)" wire:loading.attr="disabled" wire:target="downloadAndInstall" @disabled(!$installerUrl)>
                            <span wire:loading.remove wire:target="downloadAndInstall">Ya, update dan hentikan pekerjaan</span>
                            <span wire:loading wire:target="downloadAndInstall">Memproses update…</span>
                        </button>
                    @endif
                </footer>
            </section>
        </div>
    @endif
    @script
    <script>
        $wire.on('dimensimatic-update-ready', ({path}) => {
            if (window.chrome?.webview && typeof path === 'string' && path.length) window.chrome.webview.postMessage(JSON.stringify({type:'dimensimatic-update-ready',path}));
        });
    </script>
    @endscript
</div>
