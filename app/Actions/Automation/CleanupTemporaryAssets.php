<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Models\ReleaseAsset;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

final class CleanupTemporaryAssets
{
    public function handle(?string $jobId = null, bool $expiredOnly = false): int
    {
        $query = ReleaseAsset::query()->whereNotNull('temporary_path')->whereNull('deleted_at_source_cache');
        if ($jobId) {
            $query->where('release_job_id', $jobId);
        }if ($expiredOnly) {
            $query->where('created_at', '<', now()->subDay());
        }$count = 0;
        $query->each(function (ReleaseAsset $asset) use (&$count) {
            if ($asset->temporary_path) {
                $path = (string) $asset->temporary_path;
                Storage::disk('local')->delete($path);
                $directory = dirname($path);
                if (Storage::disk('local')->exists($directory)
                    && Storage::disk('local')->files($directory) === []
                    && Storage::disk('local')->directories($directory) === []) {
                    Storage::disk('local')->deleteDirectory($directory);
                }
            }$asset->update(['temporary_path' => null, 'deleted_at_source_cache' => now()]);
            $count++;
        });

        return $count;
    }

    public function purgeDownloadDirectory(): int
    {
        $count = $this->handle();
        $root = 'automation/downloads';
        if (Storage::disk('local')->exists($root)) {
            Storage::disk('local')->deleteDirectory($root);
        }
        Storage::disk('local')->makeDirectory($root);

        $workerRoot = base_path('automation-worker/storage/extracted-assets');
        if (! app()->runningUnitTests()) {
            $resolvedWorkerStorage = realpath(base_path('automation-worker/storage'));
            $resolvedWorkerRoot = realpath($workerRoot);
            if ($resolvedWorkerStorage !== false && $resolvedWorkerRoot !== false
                && str_starts_with(strtolower($resolvedWorkerRoot), strtolower(rtrim($resolvedWorkerStorage, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR))) {
                File::deleteDirectory($resolvedWorkerRoot);
            }
            File::ensureDirectoryExists($workerRoot, 0700);
        }

        return $count;
    }
}
