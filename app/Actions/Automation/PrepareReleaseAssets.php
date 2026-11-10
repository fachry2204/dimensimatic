<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Enums\ReleaseCheckpoint;
use App\Models\ReleaseAsset;
use App\Models\ReleaseJob;
use App\Models\AutomationAccount;
use App\Enums\Platform;
use App\Services\Automation\AssetDownloader;
use App\Services\Automation\AssetValidator;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class PrepareReleaseAssets
{
    public function __construct(private readonly AssetDownloader $downloader, private readonly AssetValidator $validator) {}

    public function handle(ReleaseJob $job, array $metadata): array
    {
        $this->initializeProgress($job, $metadata);
        $metadata['cover'] = $this->prepareWithProgress($job, 'cover', (array) $metadata['cover']);

        foreach ($metadata['tracks'] as $index => &$track) {
            $track['audio'] = $this->prepareWithProgress($job, 'audio', (array) $track['audio'], $index + 1);
            $track['tiktok_audio'] = $this->prepareTikTokAudioWithProgress($job, (array) ($track['tiktok_audio'] ?? []), $index + 1);
        }

        $job->update(['checkpoint' => ReleaseCheckpoint::AssetsDownloaded, 'metadata_snapshot_json' => $metadata]);

        return $metadata;
    }

    private function initializeProgress(ReleaseJob $job, array $metadata): void
    {
        $hasClip = collect($metadata['tracks'] ?? [])->contains(fn (array $track) => filled(data_get($track, 'tiktok_audio.url')));
        $job->update(['asset_progress_json' => [
            'cover' => ['label' => 'Cover Art', 'status' => 'pending'],
            'audio' => ['label' => 'Audio Master', 'status' => 'pending'],
            'tiktok_audio' => ['label' => 'Audio Clip', 'status' => $hasClip ? 'pending' : 'unavailable'],
        ]]);
    }

    private function prepareWithProgress(ReleaseJob $job, string $type, array $source, ?int $position = null): array
    {
        $this->setProgress($job, $type, 'downloading');
        try {
            $result = $this->prepare($job, $type, $source, $position);
            $this->setProgress($job, $type, 'completed');

            return $result;
        } catch (\Throwable $error) {
            $this->setProgress($job, $type, 'failed', $error->getMessage());
            throw $error;
        }
    }

    private function prepareTikTokAudioWithProgress(ReleaseJob $job, array $source, int $position): array
    {
        if (empty($source['url'])) {
            $this->setProgress($job, 'tiktok_audio', 'unavailable');

            return $this->prepareTikTokAudio($job, $source, $position);
        }

        $this->setProgress($job, 'tiktok_audio', 'downloading');
        try {
            $result = $this->prepareTikTokAudio($job, $source, $position);
            $this->setProgress($job, 'tiktok_audio', 'completed');

            return $result;
        } catch (\Throwable $error) {
            $this->setProgress($job, 'tiktok_audio', 'failed', $error->getMessage());
            throw $error;
        }
    }

    private function setProgress(ReleaseJob $job, string $type, string $status, ?string $error = null): void
    {
        $progress = $job->fresh()->asset_progress_json ?? [];
        $progress[$type] = array_merge($progress[$type] ?? [], [
            'label' => match ($type) {
                'cover' => 'Cover Art',
                'audio' => 'Audio Master',
                default => 'Audio Clip',
            },
            'status' => $status,
            'error' => $status === 'failed' ? $error : null,
            'updated_at' => now()->toIso8601String(),
        ]);
        $job->update(['asset_progress_json' => $progress]);
    }

    private function prepareTikTokAudio(ReleaseJob $job, array $source, int $position): array
    {
        // TikTok audio is optional — if the source URL is absent (not all Dimensi Suara
        // releases include a trimmed preview), skip download and return an empty array
        // so that the SoundOn draft driver can skip the upload step accordingly.
        if (empty($source['url'])) {
            // Clean up any stale cached asset from a previous attempt.
            $stale = ReleaseAsset::query()
                ->where('release_job_id', $job->id)
                ->where('type', 'tiktok_audio')
                ->where('track_position', $position)
                ->first();
            if ($stale) {
                Storage::disk('local')->delete((string) $stale->temporary_path);
                $stale->delete();
            }

            return [];
        }

        return $this->prepare($job, 'tiktok_audio', $source, $position);
    }

    private function prepare(ReleaseJob $job, string $type, array $source, ?int $position = null): array
    {
        $asset = ReleaseAsset::query()->where('release_job_id', $job->id)->where('type', $type)->where('track_position', $position)->first();
        if ($type === 'tiktok_audio' && $asset && ! $asset->source_url_encrypted) {
            Storage::disk('local')->delete((string) $asset->temporary_path);
            $asset->delete();
            $asset = null;
        }
        if (! $asset || ! $asset->temporary_path || ! Storage::disk('local')->exists($asset->temporary_path)) {
            if (empty($source['url']) || empty($source['filename'])) {
                throw new RuntimeException(strtoupper($type).'_INVALID: source URL or filename is missing.');
            }
            if (filled($source['worker_local_path'] ?? null)) {
                $asset = $this->downloader->importWorkerDownload($job, $type, (string) $source['worker_local_path'], (string) $source['filename'], $position);
            } else {
                $sessionState = AutomationAccount::query()->where('platform', Platform::Soundfresh)->first()?->session_state_encrypted;
                $asset = $this->downloader->download($job, $type, (string) $source['url'], (string) $source['filename'], $position, is_array($sessionState) ? $sessionState : null);
            }
        }
        $asset = $this->downloader->relocateToNamedPath($asset, $job);

        // Browser-assisted downloads retain their original FLAC extension and
        // older WAV uploads may use a compressed codec. Normalize every audio
        // master once before validation so SoundOn always receives PCM WAV.
        if ($type === 'audio' && empty($asset->validation_json)) {
            $asset = $this->downloader->normalizeAudioMaster($asset, $job);
        }
        if ($type === 'tiktok_audio' && (
            empty($asset->validation_json)
            || (float) data_get($asset->validation_json, 'duration', 0) > 59.0
        )) {
            $asset = $this->downloader->normalizeTikTokAudioClip($asset, $job, 59.0);
        }

        $absolutePath = Storage::disk('local')->path((string) $asset->temporary_path);
        $currentSize = is_file($absolutePath) ? filesize($absolutePath) : false;
        if (
            is_array($asset->validation_json)
            && $asset->validation_json !== []
            && $currentSize !== false
            && $currentSize > 0
            && (int) $asset->file_size === $currentSize
        ) {
            return [
                'filename' => $asset->original_filename,
                'local_path' => $absolutePath,
                'validation' => $asset->validation_json,
            ];
        }
        try {
            $validation = in_array($type, ['audio', 'tiktok_audio'], true) ? $this->validator->audio($absolutePath) : $this->validator->cover($absolutePath);
        } catch (RuntimeException $error) {
            $recoverableCacheFailure = in_array($type, ['audio', 'tiktok_audio'], true)
                && (str_contains($error->getMessage(), 'ffprobe could not read') || str_contains($error->getMessage(), 'file does not exist'));
            if (! $recoverableCacheFailure) {
                throw $error;
            }
            if (empty($source['url']) || empty($source['filename'])) {
                throw $error;
            }
            Storage::disk('local')->delete((string) $asset->temporary_path);
            $asset->delete();
            $sessionState = AutomationAccount::query()->where('platform', Platform::Soundfresh)->first()?->session_state_encrypted;
            $asset = $this->downloader->download($job, $type, (string) $source['url'], (string) $source['filename'], $position, is_array($sessionState) ? $sessionState : null);
            $asset = $this->downloader->relocateToNamedPath($asset, $job);
            if ($type === 'audio') {
                $asset = $this->downloader->normalizeAudioMaster($asset, $job);
            } elseif ($type === 'tiktok_audio') {
                $asset = $this->downloader->normalizeTikTokAudioClip($asset, $job, 59.0);
            }
            $absolutePath = Storage::disk('local')->path((string) $asset->temporary_path);
            $validation = in_array($type, ['audio', 'tiktok_audio'], true) ? $this->validator->audio($absolutePath) : $this->validator->cover($absolutePath);
        }
        $asset->update(['mime_type' => mime_content_type($absolutePath) ?: null, 'file_size' => filesize($absolutePath), 'checksum_sha256' => hash_file('sha256', $absolutePath), 'validation_json' => $validation]);

        return ['filename' => $asset->original_filename, 'local_path' => $absolutePath, 'validation' => $validation];
    }
}
