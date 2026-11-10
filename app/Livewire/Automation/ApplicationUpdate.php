<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use Illuminate\Support\Facades\Http;
use Livewire\Component;
use Throwable;

final class ApplicationUpdate extends Component
{
    public ?string $message = null;

    public string $currentVersion = '';

    public ?string $latestVersion = null;

    public ?string $latestTag = null;

    public ?string $releaseUrl = null;

    public ?string $installerName = null;

    public ?string $installerUrl = null;

    public bool $updateAvailable = false;

    public bool $installStarted = false;

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
        if (trim($repository) === '') {
            $this->message = \App\Support\SystemBranding::text('Repository update Dimensimatic belum dikonfigurasi.');

            return;
        }
        try {
            $release = Http::acceptJson()
                ->withHeaders(['User-Agent' => 'Dimensimatic-Updater'])
                ->timeout(20)
                ->get("https://api.github.com/repos/{$repository}/releases/latest")
                ->throw()
                ->json();
        } catch (Throwable $error) {
            $this->message = 'Gagal cek update dari GitHub. Periksa koneksi internet lalu coba lagi.';
            $this->updateAvailable = false;

            return;
        }

        $this->latestTag = (string) ($release['tag_name'] ?? '');
        $this->releaseUrl = (string) ($release['html_url'] ?? '');
        $asset = collect($release['assets'] ?? [])->first(function (array $asset): bool {
            $name = (string) ($asset['name'] ?? '');

            return preg_match('/^Dimensimatic-Setup-v[0-9]+\.[0-9]+\.[0-9]+\.exe$/i', $name) === 1;
        });
        $this->installerName = $asset ? (string) ($asset['name'] ?? '') : null;
        $this->installerUrl = $asset ? (string) ($asset['browser_download_url'] ?? '') : null;
        $this->latestVersion = $this->extractVersion($this->latestTag)
            ?? $this->extractVersion((string) $this->installerName)
            ?? '0.0.0';
        $this->updateAvailable = version_compare($this->latestVersion, $this->currentVersion, '>');

        if (! $this->updateAvailable) {
            $this->message = 'Aplikasi sudah versi terbaru.';

            return;
        }
        $this->message = $this->installerUrl
            ? 'Update tersedia: '.$this->latestTag
            : 'Update tersedia: '.$this->latestTag.', tetapi file installer tidak ditemukan di GitHub Release.';
    }

    public function downloadAndInstall(): void
    {
        if (! $this->updateAvailable || blank($this->installerUrl) || blank($this->installerName)) {
            $this->message = 'Cek update terlebih dahulu. Installer GitHub Release belum tersedia.';

            return;
        }

        if (preg_match('/^Dimensimatic-Setup-v[0-9]+\.[0-9]+\.[0-9]+\.exe$/i', (string) $this->installerName) !== 1) {
            $this->message = \App\Support\SystemBranding::text('Installer harus merupakan paket Dimensimatic.');

            return;
        }

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
            if (! is_file($path) || (! app()->runningUnitTests() && filesize($path) < 10_000_000)) {
                @unlink($path);
                throw new \RuntimeException('Installer update tidak lengkap.');
            }
        } catch (Throwable $error) {
            $this->message = 'Gagal download installer update. Periksa koneksi internet lalu coba lagi.';

            return;
        }

        if (PHP_OS_FAMILY === 'Windows' && ! app()->runningUnitTests()) {
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

    private function extractVersion(?string $value): ?string
    {
        if (preg_match('/(?:^|[^0-9])v?(\d+\.\d+\.\d+)(?:[^0-9]|$)/i', (string) $value, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeVersion(string $version): string
    {
        return $this->extractVersion($version) ?? '0.0.0';
    }
}
