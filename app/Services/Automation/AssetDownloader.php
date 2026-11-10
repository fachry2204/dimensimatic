<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\ReleaseAsset;
use App\Models\ReleaseJob;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AssetDownloader
{
    public function importWorkerDownload(ReleaseJob $job, string $type, string $workerPath, string $originalFilename, ?int $trackPosition = null): ReleaseAsset
    {
        $realPath = realpath($workerPath);
        $allowedRoot = realpath(base_path('automation-worker/storage/extracted-assets'));
        if ($realPath === false || $allowedRoot === false || ! str_starts_with(strtolower($realPath), strtolower(rtrim($allowedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('ASSET_DOWNLOAD_FAILED: file Cover Art hasil klik browser tidak ditemukan atau berada di lokasi yang tidak diizinkan.');
        }
        if ($type === 'cover') {
            $image = @getimagesize($realPath);
            $extension = match ($image['mime'] ?? null) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                default => throw new RuntimeException('ASSET_DOWNLOAD_FAILED: tombol Album Cover tidak menghasilkan file JPG/PNG yang valid.'),
            };
        } else {
            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            if (! in_array($extension, ['wav', 'flac'], true)) {
                throw new RuntimeException('ASSET_DOWNLOAD_FAILED: file audio browser harus berformat WAV/FLAC.');
            }
        }
        $relativePath = $this->relativePath($job, $type, $extension, $trackPosition);
        $absolutePath = Storage::disk('local')->path($relativePath);
        if (! is_dir(dirname($absolutePath))) mkdir(dirname($absolutePath), 0700, true);
        if (! copy($realPath, $absolutePath)) {
            throw new RuntimeException('ASSET_DOWNLOAD_FAILED: Cover Art hasil klik browser gagal dipindahkan ke folder download.');
        }
        @unlink($realPath);
        $size = filesize($absolutePath);
        if ($size === false || $size === 0) {
            Storage::disk('local')->delete($relativePath);
            throw new RuntimeException('ASSET_DOWNLOAD_FAILED: Cover Art hasil klik browser kosong.');
        }

        return ReleaseAsset::create(['release_job_id' => $job->id, 'type' => $type, 'track_position' => $trackPosition, 'source_url_encrypted' => null, 'temporary_path' => $relativePath, 'original_filename' => basename($relativePath), 'file_size' => $size, 'checksum_sha256' => hash_file('sha256', $absolutePath)]);
    }

    public function download(ReleaseJob $job, string $type, string $sourceUrl, string $originalFilename, ?int $trackPosition = null, ?array $sessionState = null): ReleaseAsset
    {
        $cmsBaseUrl = \App\Models\AutomationSetting::current()->cmsBaseUrl();
        $allowedHosts = array_merge(config('automation.asset_allowed_hosts', []), [strtolower((string) parse_url($cmsBaseUrl, PHP_URL_HOST))]);
        $parts = parse_url($sourceUrl);
        if (($parts['scheme'] ?? null) !== 'https' || ! in_array(strtolower((string) ($parts['host'] ?? '')), $allowedHosts, true)) {
            throw new RuntimeException('ASSET_DOWNLOAD_FAILED: source host is not allowed.');
        }

        $extension = strtolower(pathinfo(parse_url($originalFilename, PHP_URL_PATH) ?: $originalFilename, PATHINFO_EXTENSION));
        $allowedExtensions = $type === 'cover' ? ['jpg', 'jpeg', 'png'] : ['wav', 'flac'];
        if ($type === 'cover' && ! in_array($extension, $allowedExtensions, true)) {
            $urlExtension = strtolower(pathinfo((string) parse_url($sourceUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
            $extension = in_array($urlExtension, $allowedExtensions, true) ? $urlExtension : 'jpg';
        }
        if (! in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException('ASSET_DOWNLOAD_FAILED: format file tidak didukung ('.$extension.'). Cover harus JPG/PNG dan audio harus WAV/FLAC.');
        }

        $storedExtension = $extension === 'flac' ? 'wav' : $extension;
        $relativePath = $this->relativePath($job, $type, $storedExtension, $trackPosition);
        $downloadRelativePath = $extension === 'flac'
            ? preg_replace('/\.wav$/i', '.source.flac', $relativePath)
            : $relativePath;
        $absolutePath = Storage::disk('local')->path($relativePath);
        $downloadAbsolutePath = Storage::disk('local')->path($downloadRelativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0700, true);
        }

        try {
            $request = Http::connectTimeout(30)->timeout(300)->retry([1000, 3000, 7000], throw: false)
                ->withHeaders([
                    'Accept' => $type === 'cover' ? 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8' : 'audio/*,application/octet-stream,*/*;q=0.8',
                    'Referer' => rtrim($cmsBaseUrl, '/').'/',
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/127 Safari/537.36',
                ]);
            $cookies = $this->cookiesForHost($sessionState, strtolower((string) $parts['host']));
            if ($cookies !== '') {
                $request = $request->withHeaders(['Cookie' => $cookies]);
            }
            $response = $request->withOptions(['sink' => $downloadAbsolutePath, 'allow_redirects' => true])->get($sourceUrl);
            if (! $response->successful()) {
                throw new RuntimeException(\App\Support\SystemBranding::text('Server Dimensi Suara menolak unduhan dengan HTTP ').$response->status().\App\Support\SystemBranding::text('. Silakan refresh sesi Dimensi Suara lalu tekan Retry.'));
            }
            if ($type === 'cover') {
                $this->assertDownloadedCover($downloadAbsolutePath, (string) $response->header('Content-Type'));
            }
            if ($extension === 'flac') {
                $this->convertFlacToWav($downloadAbsolutePath, $absolutePath);
                Storage::disk('local')->delete($downloadRelativePath);
                $originalFilename = pathinfo($originalFilename, PATHINFO_FILENAME).'.wav';
            }
            $size = filesize($absolutePath);
            if ($size === false || $size === 0 || $size > 2 * 1024 * 1024 * 1024) {
                throw new RuntimeException('Downloaded asset size is invalid.');
            }

            return ReleaseAsset::create(['release_job_id' => $job->id, 'type' => $type, 'track_position' => $trackPosition, 'source_url_encrypted' => $sourceUrl, 'temporary_path' => $relativePath, 'original_filename' => basename($relativePath), 'file_size' => $size, 'checksum_sha256' => hash_file('sha256', $absolutePath)]);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($relativePath);
            if ($downloadRelativePath !== $relativePath) {
                Storage::disk('local')->delete($downloadRelativePath);
            }
            throw new RuntimeException('ASSET_DOWNLOAD_FAILED: '.$e->getMessage(), previous: $e);
        }
    }

    public function relocateToNamedPath(ReleaseAsset $asset, ReleaseJob $job): ReleaseAsset
    {
        $current = (string) $asset->temporary_path;
        $extension = strtolower(pathinfo($current, PATHINFO_EXTENSION));
        if ($current === '' || $extension === '') {
            return $asset;
        }

        $desired = $this->relativePath($job, (string) $asset->type, $extension, $asset->track_position ? (int) $asset->track_position : null);
        if ($current === $desired) {
            if ($asset->original_filename !== basename($desired)) {
                $asset->update(['original_filename' => basename($desired)]);
            }

            return $asset->refresh();
        }

        if (! Storage::disk('local')->exists($current)) {
            return $asset;
        }
        $targetDirectory = dirname($desired);
        Storage::disk('local')->makeDirectory($targetDirectory);
        if (Storage::disk('local')->exists($desired)) {
            Storage::disk('local')->delete($desired);
        }
        if (! Storage::disk('local')->move($current, $desired)) {
            throw new RuntimeException('ASSET_RENAME_FAILED: unable to move downloaded asset.');
        }
        $asset->update([
            'temporary_path' => $desired,
            'original_filename' => basename($desired),
        ]);

        $oldDirectory = dirname($current);
        if ($oldDirectory !== $targetDirectory
            && Storage::disk('local')->exists($oldDirectory)
            && Storage::disk('local')->files($oldDirectory) === []
            && Storage::disk('local')->directories($oldDirectory) === []) {
            Storage::disk('local')->deleteDirectory($oldDirectory);
        }

        return $asset->refresh();
    }

    public function normalizeAudioMaster(ReleaseAsset $asset, ReleaseJob $job): ReleaseAsset
    {
        if ($asset->type !== 'audio') {
            return $asset;
        }

        $sourceRelative = (string) $asset->temporary_path;
        $sourceAbsolute = Storage::disk('local')->path($sourceRelative);
        if (! is_file($sourceAbsolute)) {
            throw new RuntimeException('AUDIO_INVALID: file does not exist.');
        }

        $targetRelative = $this->relativePath($job, 'audio', 'wav', $asset->track_position ? (int) $asset->track_position : null);
        $targetAbsolute = Storage::disk('local')->path($targetRelative);
        $temporaryAbsolute = $targetAbsolute.'.normalizing.wav';
        if (! is_dir(dirname($targetAbsolute))) {
            mkdir(dirname($targetAbsolute), 0700, true);
        }

        $process = new Process([
            (string) config('automation.ffmpeg_binary', 'ffmpeg'), '-y', '-v', 'error', '-i', $sourceAbsolute,
            '-map', '0:a:0', '-vn', '-c:a', 'pcm_s24le', '-ar', '48000',
            $temporaryAbsolute,
        ]);
        $process->setTimeout(600);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($temporaryAbsolute) || filesize($temporaryAbsolute) === 0) {
            @unlink($temporaryAbsolute);
            $detail = trim($process->getErrorOutput());
            throw new RuntimeException('AUDIO_INVALID: conversion to PCM WAV failed'.($detail !== '' ? ': '.$detail : '.'));
        }

        if (is_file($targetAbsolute)) {
            @unlink($targetAbsolute);
        }
        if (! @rename($temporaryAbsolute, $targetAbsolute)) {
            @unlink($temporaryAbsolute);
            throw new RuntimeException('AUDIO_INVALID: normalized WAV could not be saved.');
        }
        if ($sourceAbsolute !== $targetAbsolute) {
            @unlink($sourceAbsolute);
        }

        $asset->update([
            'temporary_path' => $targetRelative,
            'original_filename' => basename($targetRelative),
            'mime_type' => 'audio/wav',
            'file_size' => filesize($targetAbsolute),
            'checksum_sha256' => hash_file('sha256', $targetAbsolute),
            'validation_json' => null,
        ]);

        return $asset->refresh();
    }

    public function normalizeTikTokAudioClip(ReleaseAsset $asset, ReleaseJob $job, float $maximumSeconds = 59.0): ReleaseAsset
    {
        if ($asset->type !== 'tiktok_audio') {
            return $asset;
        }

        $sourceRelative = (string) $asset->temporary_path;
        $sourceAbsolute = Storage::disk('local')->path($sourceRelative);
        if (! is_file($sourceAbsolute)) {
            throw new RuntimeException('TIKTOK_AUDIO_INVALID: file does not exist.');
        }

        $targetRelative = $this->relativePath($job, 'tiktok_audio', 'wav', $asset->track_position ? (int) $asset->track_position : null);
        $targetAbsolute = Storage::disk('local')->path($targetRelative);
        $temporaryAbsolute = $targetAbsolute.'.trimming.wav';
        if (! is_dir(dirname($targetAbsolute))) {
            mkdir(dirname($targetAbsolute), 0700, true);
        }

        $process = new Process([
            (string) config('automation.ffmpeg_binary', 'ffmpeg'), '-y', '-v', 'error', '-i', $sourceAbsolute,
            '-map', '0:a:0', '-vn', '-t', number_format($maximumSeconds, 3, '.', ''),
            '-c:a', 'pcm_s24le', '-ar', '48000',
            $temporaryAbsolute,
        ]);
        $process->setTimeout(600);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($temporaryAbsolute) || filesize($temporaryAbsolute) === 0) {
            @unlink($temporaryAbsolute);
            $detail = trim($process->getErrorOutput());
            throw new RuntimeException('TIKTOK_AUDIO_INVALID: clip could not be trimmed to 59 seconds'.($detail !== '' ? ': '.$detail : '.'));
        }

        if (is_file($targetAbsolute)) {
            @unlink($targetAbsolute);
        }
        if (! @rename($temporaryAbsolute, $targetAbsolute)) {
            @unlink($temporaryAbsolute);
            throw new RuntimeException('TIKTOK_AUDIO_INVALID: trimmed clip could not be saved.');
        }
        if ($sourceAbsolute !== $targetAbsolute) {
            @unlink($sourceAbsolute);
        }

        $asset->update([
            'temporary_path' => $targetRelative,
            'original_filename' => basename($targetRelative),
            'mime_type' => 'audio/wav',
            'file_size' => filesize($targetAbsolute),
            'checksum_sha256' => hash_file('sha256', $targetAbsolute),
            'validation_json' => null,
        ]);

        return $asset->refresh();
    }

    public function relativePath(ReleaseJob $job, string $type, string $extension, ?int $trackPosition = null): string
    {
        $title = $this->safeName((string) ($job->release_title ?: 'Untitled Release'));
        $artist = $this->safeName((string) ($job->artist_name ?: 'Unknown Artist'));
        $folder = $title.' - '.$artist;
        $base = $title.'-'.$artist;
        $trackSuffix = (int) $job->track_count > 1 && $trackPosition !== null
            ? '-track-'.$trackPosition
            : '';
        $suffix = match ($type) {
            'tiktok_audio' => $trackSuffix.'-trim',
            'audio' => $trackSuffix,
            default => '',
        };

        return 'automation/downloads/'.$folder.'/'.$base.$suffix.'.'.strtolower($extension);
    }

    private function safeName(string $value): string
    {
        $clean = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F]/u', '-', trim($value)) ?? '';
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = preg_replace('/-+/u', '-', $clean) ?? $clean;
        $clean = trim($clean, " .-");

        return mb_substr($clean !== '' ? $clean : 'Unnamed', 0, 100);
    }

    private function convertFlacToWav(string $sourcePath, string $targetPath): void
    {
        $process = new Process([(string) config('automation.ffmpeg_binary', 'ffmpeg'), '-y', '-v', 'error', '-i', $sourcePath, '-map', '0:a:0', '-c:a', 'pcm_s24le', $targetPath]);
        $process->setTimeout(300);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($targetPath) || filesize($targetPath) === 0) {
            $detail = trim($process->getErrorOutput());
            throw new RuntimeException('FLAC conversion to PCM WAV failed'.($detail !== '' ? ': '.$detail : '.'));
        }
    }

    private function assertDownloadedCover(string $path, string $contentType): void
    {
        $info = @getimagesize($path);
        if ($info && in_array($info['mime'] ?? null, ['image/jpeg', 'image/png'], true)) {
            return;
        }

        $prefix = is_file($path) ? strtolower((string) file_get_contents($path, false, null, 0, 512)) : '';
        if (str_contains(strtolower($contentType), 'text/html') || str_contains($prefix, '<html') || str_contains($prefix, '<!doctype')) {
            throw new RuntimeException(\App\Support\SystemBranding::text('Cover Art tidak terunduh karena Dimensi Suara mengembalikan halaman login/HTML. Login atau refresh sesi Dimensi Suara, lalu tekan Retry.'));
        }

        throw new RuntimeException('Cover Art yang diterima bukan file JPG/PNG yang valid'.($contentType !== '' ? ' (Content-Type: '.$contentType.')' : '.'));
    }

    private function cookiesForHost(?array $sessionState, string $host): string
    {
        if (! $sessionState) {
            return '';
        }

        return collect($sessionState['cookies'] ?? [])
            ->filter(function (array $cookie) use ($host): bool {
                $domain = ltrim(strtolower((string) ($cookie['domain'] ?? '')), '.');

                return $domain !== '' && ($host === $domain || str_ends_with($host, '.'.$domain));
            })
            ->map(fn (array $cookie): string => rawurlencode((string) $cookie['name']).'='.(string) $cookie['value'])
            ->implode('; ');
    }
}
