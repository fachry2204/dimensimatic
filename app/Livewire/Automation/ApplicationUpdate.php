<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Throwable;
use App\Services\Automation\UpdateReleaseFeed;
use App\Actions\Automation\StopWorkForUpdate;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Cache;
use App\Services\Automation\UpdateBackup;
use Livewire\Attributes\Locked;

class ApplicationUpdate extends Component
{
    public ?string $message = null;

    #[Locked]
    public string $currentVersion = '';

    #[Locked]
    public ?string $latestVersion = null;

    #[Locked]
    public ?string $latestTag = null;

    public ?string $releaseUrl = null;

    #[Locked]
    public ?string $installerName = null;

    #[Locked]
    public ?string $installerUrl = null;

    #[Locked]
    public ?string $installerSha256 = null;

    #[Locked]
    public bool $updateAvailable = false;

    #[Locked]
    public bool $installStarted = false;

    #[Locked]
    public bool $downloadReady = false;

    public function mount(): void
    {
        $environmentVersion = $this->normalizeVersion((string) config('automation.app_version', '1.1.40'));
        $bundledVersion = $this->normalizeVersion((string) config('automation.bundled_app_version', $environmentVersion));

        $this->currentVersion = version_compare($bundledVersion, $environmentVersion, '>')
            ? $bundledVersion
            : $environmentVersion;
    }

    public function checkForUpdate(): void
    {
        $repository = (string) config('automation.update_repository', '');
        $this->updateAvailable = false;
        $this->installerName = null;
        $this->installerUrl = null;
        $this->installerSha256 = null;
        $this->latestVersion = null;
        $this->latestTag = null;
        $this->releaseUrl = null;
        if (trim($repository) === '') {
            $this->message = \App\Support\SystemBranding::text('Repository update Dimensimatic belum dikonfigurasi.');

            return;
        }
        try {
            $release = app(UpdateReleaseFeed::class)->checkNow();
        } catch (Throwable $error) {
            $this->message = $error instanceof \RuntimeException ? $error->getMessage() : 'Gagal cek update dari GitHub. Periksa koneksi internet lalu coba lagi.';
            $this->updateAvailable = false;

            return;
        }

        $this->applyRelease($release);
    }

    protected function applyRelease(array $release): void
    {
        $this->latestTag = (string) ($release['tag_name'] ?? '');
        $this->releaseUrl = (string) ($release['html_url'] ?? '');
        $asset = collect($release['assets'] ?? [])->first(function (array $asset): bool {
            $name = (string) ($asset['name'] ?? '');

            return preg_match('/^Dimensimatic-Setup-v[0-9]+\.[0-9]+\.[0-9]+\.exe$/i', $name) === 1;
        });
        $this->installerName = $asset ? (string) ($asset['name'] ?? '') : null;
        $this->installerUrl = $asset ? (string) ($asset['browser_download_url'] ?? '') : null;
        $this->installerSha256 = preg_match('/^sha256:([a-f0-9]{64})$/i', (string) ($asset['digest'] ?? ''), $digest) ? strtolower($digest[1]) : null;
        $this->latestVersion = $this->extractVersion($this->latestTag)
            ?? $this->extractVersion((string) $this->installerName)
            ?? '0.0.0';
        if ($this->extractVersion($this->installerName) !== $this->latestVersion) $this->installerUrl = null;
        $this->updateAvailable = version_compare($this->latestVersion, $this->currentVersion, '>');

        if (! $this->updateAvailable) {
            $this->message = 'Aplikasi sudah versi terbaru.';

            return;
        }
        $this->message = $this->installerUrl
            ? 'Update tersedia: '.$this->latestTag
            : 'Update tersedia: '.$this->latestTag.', tetapi file installer tidak ditemukan di GitHub Release.';
    }

    public function requestInstall(): void
    {
        Gate::authorize('sessions.manage');
        $this->dispatch('show-update-confirmation');
    }

    public function downloadAndInstall(bool $confirmed = false, bool $desktop = false): void
    {
        if (! $this->updateAvailable || blank($this->installerUrl) || blank($this->installerName)) {
            $this->message = 'Cek update terlebih dahulu. Installer GitHub Release belum tersedia.';

            return;
        }

        Gate::authorize('sessions.manage');
        if (!$confirmed) {
            $this->message = 'Konfirmasi diperlukan: update sistem dan hentikan semua pekerjaan terlebih dahulu.';
            return;
        }
        if ($this->installStarted) return;
        $repository = (string) config('automation.update_repository');
        $expectedUrl = "https://github.com/{$repository}/releases/download/{$this->latestTag}/{$this->installerName}";
        if ($this->installerUrl !== $expectedUrl || !version_compare((string) $this->latestVersion, $this->currentVersion, '>')) {
            $this->message = 'Sumber atau versi installer tidak valid. Cek update kembali.';
            return;
        }
        if (preg_match('/^Dimensimatic-Setup-v[0-9]+\.[0-9]+\.[0-9]+\.exe$/i', (string) $this->installerName) !== 1) {
            $this->message = \App\Support\SystemBranding::text('Installer harus merupakan paket Dimensimatic.');

            return;
        }

        $lock = Cache::store('file')->lock('dimensimatic-update-install:'.sha1(base_path()), 1900);
        if (!$lock->get()) { $this->message = 'Update lain sedang diproses.'; return; }
        try {
            $this->performDownloadAndInstall($desktop);
        } finally { $lock->release(); }
    }

    protected function performDownloadAndInstall(bool $desktop): void
    {
        try {
            $directory = storage_path('app/updates');
            if (! is_dir($directory)) {
                mkdir($directory, 0755, true);
            }
            $path = $directory.DIRECTORY_SEPARATOR.basename($this->installerName);
            Http::withHeaders(['User-Agent' => 'Dimensimatic-Updater'])
                ->withOptions(['sink' => $path])
                ->timeout(1800)
                ->get($this->installerUrl)
                ->throw();
            if (! is_file($path) || (! app()->runningUnitTests() && (filesize($path) < 10_000_000 || file_get_contents($path, false, null, 0, 2) !== 'MZ'))) {
                @unlink($path);
                throw new \RuntimeException('Installer update tidak lengkap.');
            }
            if ($this->installerSha256 && !hash_equals($this->installerSha256, hash_file('sha256', $path))) {
                @unlink($path);
                throw new \RuntimeException('Checksum installer tidak cocok.');
            }
        } catch (Throwable $error) {
            $this->message = 'Paket update belum siap: '.$error->getMessage(). ' Pekerjaan belum dihentikan.';

            return;
        }

        try {
            app(StopWorkForUpdate::class)->handle();
            app(UpdateBackup::class)->capture();
        } catch (Throwable $error) {
            $this->message = 'Installer tidak dijalankan: '.$error->getMessage();
            return;
        }

        $this->downloadReady = true;
        if ($desktop && PHP_OS_FAMILY === 'Windows' && ! app()->runningUnitTests()) {
            // PHP runs as a child service of the desktop host. Hand the installer
            // path to WebView2 so the desktop process can launch it independently
            // and close itself immediately. Launching from this short-lived HTTP
            // request can dispose the child before its detached bootstrap runs.
            $this->installStarted = true;
            $this->message = 'Installer update v'.$this->latestVersion.\App\Support\SystemBranding::text(' siap dijalankan. Dimensimatic akan ditutup, diperbarui, lalu dibuka kembali.');
            $this->dispatch('dimensimatic-update-ready', path: $path);

            return;
        }

        $this->message = 'Installer update v'.$this->latestVersion.' berhasil didownload. Jalankan file installer untuk menyelesaikan update.';
    }

    public function render()
    {
        return view('livewire.automation.application-update');
    }

    protected function extractVersion(?string $value): ?string
    {
        if (preg_match('/(?:^|[^0-9])v?(\d+\.\d+\.\d+)(?:[^0-9]|$)/i', (string) $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function normalizeVersion(string $version): string
    {
        return $this->extractVersion($version) ?? '0.0.0';
    }
}
