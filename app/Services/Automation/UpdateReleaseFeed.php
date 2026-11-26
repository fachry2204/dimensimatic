<?php

namespace App\Services\Automation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class UpdateReleaseFeed
{
    public function key(): string
    {
        return 'dimensimatic-release:'.sha1(base_path().'|'.config('automation.update_repository'));
    }

    public function snapshot(): ?array
    {
        $cache = Cache::store(app()->runningUnitTests() ? 'array' : 'file');
        $snapshot = $cache->get($this->key());
        if ((!$snapshot || ($snapshot['checked_at'] ?? 0) < time() - 120)
            && $cache->add($this->key().':checking', true, 30)) {
            try {
                app(LocalSystemManager::class)->startUpdateCheck();
            } catch (Throwable $e) {
                $cache->forget($this->key().':checking');
                logger()->warning('update_background_check_failed', ['exception_class' => $e::class]);
            }
        }
        return $snapshot;
    }

    public function checkNow(): array
    {
        try {
            $repository = (string) config('automation.update_repository');
            if (!preg_match('~^[\w.-]+/[\w.-]+$~', $repository)) throw new RuntimeException('Repository update DimensiMatic belum dikonfigurasi.');
            $response = Http::acceptJson()->withHeaders(['User-Agent' => 'Dimensimatic-Updater'])
                ->connectTimeout(3)->timeout(10)->get("https://api.github.com/repos/{$repository}/releases/latest");
            if ($response->failed()) throw new RuntimeException(match ($response->status()) {
                404 => 'GitHub tidak menyediakan Release yang dapat diakses (HTTP 404). Repository mungkin privat, alamat salah, atau belum memiliki Release publik.',
                401 => 'Akses GitHub ditolak (HTTP 401).',
                403, 429 => 'GitHub membatasi atau menolak akses update (HTTP '.$response->status().'). Coba lagi nanti.',
                default => 'Pemeriksaan GitHub gagal (HTTP '.$response->status().').',
            });
            $release = $response->json();
            if (!is_array($release) || !is_string($release['tag_name'] ?? null) || ($release['draft'] ?? false) || ($release['prerelease'] ?? false)) {
                throw new RuntimeException('Respons GitHub bukan data Release stabil yang valid.');
            }
            Cache::store(app()->runningUnitTests() ? 'array' : 'file')->put($this->key(), ['release' => $release, 'checked_at' => time()], 86400);
            return $release;
        } catch (Throwable $error) {
            Cache::store(app()->runningUnitTests() ? 'array' : 'file')->put($this->key(), ['error' => $error->getMessage(), 'checked_at' => time()], 120);
            throw $error;
        } finally {
            Cache::store(app()->runningUnitTests() ? 'array' : 'file')->forget($this->key().':checking');
        }
    }
}
