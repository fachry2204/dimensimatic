<div>
    <x-automation-nav />
    <main class="page-shell">
        <section class="page-heading">
            <div><div class="eyebrow">Platform connections</div><h1 class="page-title">Pengaturan Platform</h1><p class="page-subtitle">Kelola credential terenkripsi dan kesehatan sesi {{ $cmsName }} serta SoundOn. Password tersimpan tidak pernah ditampilkan kembali.</p></div>
        </section>

        @if ($message)
            <div class="info-banner mb-6"><strong>Status terbaru</strong><span>{{ \App\Support\SystemBranding::text($message) }}</span></div>
        @endif

        <article class="panel mb-6 overflow-hidden">
            <header class="panel-header"><div><h2 class="panel-title">Identitas Sistem</h2><p class="panel-copy">Nama ini digunakan pada judul aplikasi, navigasi, halaman login, dan label CMS.</p></div></header>
            <form wire:submit="saveSystemIdentity" x-data="{ uploading: false }" x-on:livewire-upload-start="uploading = true" x-on:livewire-upload-finish="uploading = false" x-on:livewire-upload-error="uploading = false" x-on:livewire-upload-cancel="uploading = false" class="space-y-4 p-5">
                <div><label for="system-name" class="form-label">Nama sistem</label><input id="system-name" wire:model="systemName" class="form-input" maxlength="80" required>@error('systemName')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                <div><label for="system-favicon-upload" class="form-label">Favicon</label><input id="system-favicon-upload" type="file" wire:model="faviconUpload" accept=".png,.ico,image/png,image/x-icon" class="form-input"><p class="mt-1 text-xs text-slate-400">PNG atau ICO, maksimal 512 KB. PNG maksimal 1024 × 1024 piksel.</p>@error('faviconUpload')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror</div>
                @if($systemFavicon)<img src="{{ $systemFavicon }}" alt="Favicon tersimpan" class="h-12 w-12 rounded-lg object-contain"><label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="removeFavicon">Kembalikan favicon bawaan</label>@endif
                <p class="text-xs text-slate-400">Identitas instalasi Windows tetap Dimensimatic agar terpisah dari aplikasi lain.</p>
                @if($faviconUpload)<p class="text-xs text-slate-500">Favicon siap disimpan: {{ $faviconUpload->getClientOriginalName() }}</p>@endif
                <button class="primary-button" x-bind:disabled="uploading" wire:loading.attr="disabled" wire:target="saveSystemIdentity,faviconUpload">Simpan identitas sistem</button>
                <span wire:loading wire:target="faviconUpload" class="text-sm text-slate-500">Mengunggah favicon...</span>
            </form>
        </article>

        <article class="panel mb-6 overflow-hidden">
            <header class="panel-header">
                <div><h2 class="panel-title">Link Login CMS</h2><p class="panel-copy">Alamat login {{ $cmsName }}. Domain ini juga digunakan untuk akses rilisan dan unduhan CMS.</p></div>
            </header>
            <form wire:submit="saveCmsLoginUrl" class="space-y-4 p-5">
                <div>
                    <label for="cms-login-url" class="form-label">Link login CMS {{ $cmsName }}</label>
                    <input id="cms-login-url" type="url" wire:model="cmsLoginUrl" class="form-input" placeholder="https://dimensisuara.id/login" required>
                    @error('cmsLoginUrl')<p class="mt-1 text-xs text-red-600">{{ \App\Support\SystemBranding::text($message) }}</p>@enderror
                    <p class="mt-1 text-xs text-slate-400">Setelah alamat berubah, login kembali untuk membuat sesi pada alamat baru.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button class="primary-button" wire:loading.attr="disabled" wire:target="saveCmsLoginUrl">Simpan link login CMS</button>
                    <a href="{{ \App\Models\AutomationSetting::current()->cmsLoginUrl() }}" target="_blank" rel="noopener noreferrer" class="secondary-button">Buka login {{ $cmsName }} ↗</a>
                </div>
            </form>
        </article>

        <article class="panel mb-6 overflow-hidden">
            <header class="panel-header">
                <div>
                    <h2 class="panel-title">Otomatis Berjalan</h2>
                    <p class="panel-copy">Jika aktif, sistem otomatis menjalankan Ambil Rilis dan Cek Status sesuai interval dan jam kerja.</p>
                </div>
                <span class="badge {{ $automaticRunEnabled ? 'badge--success' : 'badge--warning' }}">{{ $automaticRunEnabled ? 'Aktif' : 'Nonaktif' }}</span>
            </header>
            <form wire:submit="saveAutomaticRunSettings" class="grid gap-4 p-5 lg:grid-cols-4">
                <label class="flex items-center gap-3 rounded-xl border border-slate-200 px-4 py-3 text-sm font-semibold text-slate-700 lg:col-span-4">
                    <input type="checkbox" wire:model="automaticRunEnabled" class="h-4 w-4 rounded border-slate-300 text-indigo-600">
                    Aktifkan otomatis berjalan
                </label>
                <div>
                    <label class="form-label">Interval pengambilan status</label>
                    <input type="number" min="5" max="1440" wire:model="automaticRunIntervalMinutes" class="form-input">
                    <p class="mt-1 text-xs text-slate-400">Dalam menit. Minimal 5 menit.</p>
                </div>
                <div>
                    <label class="form-label">Jam mulai</label>
                    <input type="time" wire:model="automaticRunStartTime" class="form-input">
                </div>
                <div>
                    <label class="form-label">Jam selesai</label>
                    <input type="time" wire:model="automaticRunEndTime" class="form-input">
                    <p class="mt-1 text-xs text-slate-400">Boleh melewati tengah malam.</p>
                </div>
                <div class="flex items-end">
                    <button class="primary-button w-full" wire:loading.attr="disabled" wire:target="saveAutomaticRunSettings">
                        <span wire:loading.remove wire:target="saveAutomaticRunSettings">Simpan jadwal</span>
                        <span wire:loading wire:target="saveAutomaticRunSettings">Menyimpan...</span>
                    </button>
                </div>
            </form>
        </article>

        <div class="grid gap-5 lg:grid-cols-2">
            @foreach (['soundfresh' => $cmsName, 'soundon' => 'SoundOn'] as $key => $label)
                @php($account = $accounts->get($key))
                @php($active = $account?->status === 'active')
                @php($statusLabel = match ($account?->status) {
                    'active' => 'Aktif',
                    'credentials_need_reentry' => 'Isi ulang credential',
                    'authentication_failed' => 'Login gagal',
                    'manual_auth_required' => 'Perlu login manual',
                    'expired' => 'Sesi kedaluwarsa',
                    'disabled' => 'Dinonaktifkan',
                    default => 'Belum dikonfigurasi',
                })
                <article class="panel overflow-hidden">
                    <header class="panel-header">
                        <div class="flex items-center gap-3">
                            <span class="grid h-11 w-11 place-items-center rounded-xl {{ $key === 'soundfresh' ? 'bg-violet-100 text-violet-700' : 'bg-blue-100 text-blue-700' }} font-bold">{{ $key === 'soundfresh' ? mb_strtoupper(mb_substr($systemName, 0, 2)) : 'SO' }}</span>
                            <div><h2 class="panel-title">{{ $label }}</h2><p class="panel-copy">Akun sumber {{ $key === 'soundfresh' ? 'metadata rilisan' : 'draft distribusi' }}</p></div>
                        </div>
                        <span class="badge {{ $active ? 'badge--success' : 'badge--warning' }}"><span class="status-dot {{ $active ? 'status-dot--active' : 'status-dot--warning' }}"></span>{{ $statusLabel }}</span>
                    </header>

                    <div class="p-5">
                        <form wire:submit="saveAndLogin('{{ $key }}')" class="space-y-4">
                            <div><label class="form-label">Email akun {{ $label }}</label><input type="email" wire:model="emails.{{ $key }}" autocomplete="username" class="form-input" placeholder="nama@perusahaan.com" required></div>
                            <div><label class="form-label">Password</label><input type="password" wire:model="passwords.{{ $key }}" autocomplete="new-password" placeholder="{{ $credentialAvailability[$key] ?? false ? 'Kosongkan jika tidak diubah' : 'Masukkan password' }}" class="form-input"></div>
                            <button class="primary-button" wire:loading.attr="disabled" wire:target="saveAndLogin('{{ $key }}')"><span wire:loading.remove wire:target="saveAndLogin('{{ $key }}')">Simpan &amp; login</span><span wire:loading wire:target="saveAndLogin('{{ $key }}')">Menyimpan dan login...</span></button>
                        </form>

                        <div class="my-5 h-px bg-slate-100"></div>
                        <dl class="grid grid-cols-3 gap-3 text-xs">
                            <div><dt class="text-slate-400">Credential</dt><dd class="mt-1 font-semibold text-slate-700">{{ $credentialAvailability[$key] ?? false ? 'Tersimpan' : 'Perlu diisi ulang' }}</dd></div>
                            <div><dt class="text-slate-400">Login terakhir</dt><dd class="mt-1 font-semibold text-slate-700">{{ $account?->last_authenticated_at?->diffForHumans() ?? '—' }}</dd></div>
                            <div><dt class="text-slate-400">Kedaluwarsa</dt><dd class="mt-1 font-semibold text-slate-700">{{ $account?->session_expires_at?->format('d M, H:i') ?? '—' }}</dd></div>
                        </dl>

                        <div class="mt-5 flex flex-wrap items-center gap-3">
                            <button type="button" wire:click="refresh('{{ $key }}')" @disabled(! ($credentialAvailability[$key] ?? false)) wire:loading.attr="disabled" wire:target="refresh('{{ $key }}')" class="secondary-button disabled:cursor-not-allowed disabled:opacity-50" title="{{ $credentialAvailability[$key] ?? false ? 'Login menggunakan credential tersimpan' : 'Isi email dan password lalu tekan Simpan & login' }}"><span wire:loading.remove wire:target="refresh('{{ $key }}')">Login / refresh sesi</span><span wire:loading wire:target="refresh('{{ $key }}')">Sedang login...</span></button>
                            <button type="button" wire:click="clear('{{ $key }}')" wire:confirm="Hapus cached session {{ $label }}?" class="text-xs font-semibold text-amber-700">Hapus sesi</button>
                            <button type="button" wire:click="clearCredentials('{{ $key }}')" wire:confirm="Hapus credential dan session {{ $label }}?" class="text-xs font-semibold text-red-600">Hapus credential</button>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>

        <article class="panel mt-6 overflow-hidden">
            <header class="panel-header">
                <div>
                    <h2 class="panel-title">Penyimpanan hasil download</h2>
                    <p class="panel-copy">Cover Art, Audio Master, dan Audio Clip otomatis dihapus setelah draft SoundOn berhasil disimpan.</p>
                </div>
                <span class="badge badge--success">{{ $downloadFiles }} file · {{ $downloadSize }}</span>
            </header>
            <div class="flex flex-wrap items-center gap-3 p-5">
                <button type="button" wire:click="openDownloadFolder" class="secondary-button">Buka folder download</button>
                <button type="button" wire:click="clearDownloadData" wire:confirm="Hapus seluruh Cover Art, Audio Master, Audio Clip, dan cache worker? Semua proses upload yang sedang berjalan atau mengantre akan dihentikan." wire:loading.attr="disabled" wire:target="clearDownloadData" class="primary-button bg-red-600 hover:bg-red-700">
                    <span wire:loading.remove wire:target="clearDownloadData">Hapus data download</span>
                    <span wire:loading wire:target="clearDownloadData">Menghapus...</span>
                </button>
            </div>
        </article>

        <div class="info-banner mt-6"><strong>Keamanan autentikasi</strong><span>Jika muncul CAPTCHA atau OTP, status berubah menjadi manual authentication required. Sistem tidak mencoba melakukan bypass.</span></div>
    </main>
</div>
