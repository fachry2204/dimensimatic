<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div>
                <div class="eyebrow">Application maintenance</div>
                <h1 class="page-title">Update Aplikasi</h1>
                <p class="page-subtitle">Cek GitHub Release terbaru, download installer, lalu jalankan pembaruan {{ $systemName }}.</p>
            </div>
            <span class="badge badge--brand">Versi {{ $currentVersion }}</span>
        </section>

        @if ($message)
            <div class="info-banner mb-6" role="status"><strong>Status update</strong><span>{{ \App\Support\SystemBranding::text($message) }}</span></div>
        @endif

        <article class="panel overflow-hidden">
            <header class="panel-header">
                <div>
                    <h2 class="panel-title">GitHub Update</h2>
                    <p class="panel-copy">Repository: {{ config('automation.update_repository') }}</p>
                </div>
                @if($updateAvailable)
                    <span class="badge badge--success">Update tersedia</span>
                @else
                    <span class="badge badge--brand">Versi lokal {{ $currentVersion }}</span>
                @endif
            </header>
            <div class="grid gap-5 p-5 md:grid-cols-2">
                <div>
                    <p class="text-xs font-semibold text-slate-500">Versi terpasang</p>
                    <p class="mt-2 text-2xl font-bold text-slate-800">v{{ $currentVersion }}</p>
                </div>
                <div>
                    <p class="text-xs font-semibold text-slate-500">Versi GitHub terbaru</p>
                    <p class="mt-2 text-2xl font-bold text-slate-800">{{ $latestVersion ? 'v'.$latestVersion : 'Belum dicek' }}</p>
                    @if($installerName)<p class="mt-1 text-xs text-slate-400">{{ $installerName }}</p>@endif
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-3 border-t border-slate-100 p-5">
                <button type="button" wire:click="checkForUpdate" wire:loading.attr="disabled" wire:target="checkForUpdate" class="secondary-button">
                    <span wire:loading.remove wire:target="checkForUpdate">Cek Update</span>
                    <span wire:loading wire:target="checkForUpdate">Mengecek GitHub...</span>
                </button>
                <button type="button" wire:click="downloadAndInstall" wire:confirm="Download dan jalankan installer update {{ $systemName }} sekarang? Setelah download selesai, aplikasi otomatis ditutup dan installer dijalankan." wire:loading.attr="disabled" wire:target="downloadAndInstall" @disabled(! $updateAvailable || ! $installerUrl) class="primary-button">
                    <span wire:loading.remove wire:target="downloadAndInstall">Download &amp; Install Update</span>
                    <span wire:loading wire:target="downloadAndInstall">Download update...</span>
                </button>
                @if($releaseUrl)<a href="{{ $releaseUrl }}" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold text-indigo-600">Lihat GitHub Release ↗</a>@endif
            </div>
            <div class="border-t border-slate-100 px-5 pb-5" aria-live="polite">
                <div class="mb-2 flex items-center justify-between text-xs font-semibold text-slate-500">
                    <span>Progress download update</span>
                    <span wire:loading.remove wire:target="downloadAndInstall">Siap</span>
                    <span wire:loading wire:target="downloadAndInstall">Sedang mengunduh dan memverifikasi...</span>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-label="Progress download update">
                    <div wire:loading.remove wire:target="downloadAndInstall" class="h-full w-0 rounded-full bg-indigo-600"></div>
                    <div wire:loading wire:target="downloadAndInstall" class="h-full w-2/3 animate-pulse rounded-full bg-indigo-600"></div>
                </div>
                <p class="mt-2 text-xs text-slate-400">Aplikasi otomatis ditutup setelah download selesai, installer terbuka otomatis, lalu file installer dihapus otomatis setelah update.</p>
            </div>
        </article>

        <div class="info-banner mt-6"><strong>Syarat update</strong><span>GitHub Release harus memakai tag versi seperti v1.1.41 dan memiliki asset installer bernama Dimensimatic-Setup-v1.1.41.exe.</span></div>
    </main>

    @script
    <script>
        $wire.on('dimensimatic-update-ready', ({ path }) => {
            if (!window.chrome?.webview || typeof path !== 'string' || path.length === 0) {
                return;
            }

            window.chrome.webview.postMessage(JSON.stringify({
                type: 'dimensimatic-update-ready',
                path,
            }));
        });
    </script>
    @endscript
</div>
