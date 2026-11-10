<?php

namespace Tests\Unit;

use App\Models\AutomationRun;
use App\Models\ReleaseJob;
use App\Services\Automation\AssetDownloader;
use App\Services\Automation\AssetValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class AssetDownloaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_rejects_unapproved_asset_host_before_network_request(): void
    {
        config(['automation.asset_allowed_hosts' => ['cms.soundfresh.id']]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source host is not allowed');
        app(AssetDownloader::class)->download(new ReleaseJob, 'audio', 'https://evil.example/master.wav', 'master.wav');
    }

    public function test_converts_soundfresh_flac_audio_to_pcm_wav(): void
    {
        Storage::fake('local');
        config(['automation.asset_allowed_hosts' => ['cms.soundfresh.id']]);

        $fixture = tempnam(sys_get_temp_dir(), 'dimensimatic-flac-').'.flac';
        $generate = new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.1', '-c:a', 'flac', $fixture]);
        $generate->mustRun();
        Http::fake(['cms.soundfresh.id/*' => Http::response(file_get_contents($fixture), 200, ['Content-Type' => 'audio/flac'])]);

        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2001',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2001',
            'idempotency_key' => 'soundfresh:2001:soundon',
            'release_title' => 'Hingga Akhir',
            'artist_name' => 'Introspektif',
            'track_count' => 1,
        ]);

        try {
            $asset = app(AssetDownloader::class)->download(
                $job,
                'audio',
                'https://cms.soundfresh.id/file/access/17335',
                'Hingga Akhir - Introspektif.flac',
            );

            $this->assertSame('Hingga Akhir-Introspektif.wav', $asset->original_filename);
            $this->assertSame(
                'automation/downloads/Hingga Akhir - Introspektif/Hingga Akhir-Introspektif.wav',
                $asset->temporary_path,
            );
            $this->assertStringEndsWith('.wav', (string) $asset->temporary_path);
            $this->assertFileExists(Storage::disk('local')->path((string) $asset->temporary_path));
            $this->assertSame('RIFF', file_get_contents(Storage::disk('local')->path((string) $asset->temporary_path), false, null, 0, 4));
        } finally {
            @unlink($fixture);
        }
    }

    public function test_imports_cover_downloaded_by_authenticated_browser_click(): void
    {
        Storage::fake('local');
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'cover-click',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/cover-click',
            'idempotency_key' => 'soundfresh:cover-click:soundon',
            'release_title' => 'Cover Click',
            'artist_name' => 'Artist',
            'track_count' => 1,
        ]);
        $directory = base_path('automation-worker/storage/extracted-assets');
        if (! is_dir($directory)) mkdir($directory, 0700, true);
        $workerFile = $directory.'/'.uniqid('cover-', true).'.png';
        file_put_contents($workerFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        try {
            $asset = app(AssetDownloader::class)->importWorkerDownload($job, 'cover', $workerFile, 'Album Cover.png');
            $this->assertSame('automation/downloads/Cover Click - Artist/Cover Click-Artist.png', $asset->temporary_path);
            Storage::disk('local')->assertExists($asset->temporary_path);
            $this->assertFileDoesNotExist($workerFile);
        } finally {
            @unlink($workerFile);
        }
    }

    public function test_imports_audio_downloaded_by_authenticated_browser_context(): void
    {
        Storage::fake('local');
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'audio-browser',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/audio-browser',
            'idempotency_key' => 'soundfresh:audio-browser:soundon', 'release_title' => 'Audio Browser',
            'artist_name' => 'Artist', 'track_count' => 1,
        ]);
        $directory = base_path('automation-worker/storage/extracted-assets');
        if (! is_dir($directory)) mkdir($directory, 0700, true);
        $workerFile = $directory.'/'.uniqid('audio-', true).'.wav';
        file_put_contents($workerFile, 'RIFF-test-audio');

        try {
            $asset = app(AssetDownloader::class)->importWorkerDownload($job, 'audio', $workerFile, 'Master.wav', 1);
            Storage::disk('local')->assertExists($asset->temporary_path);
            $this->assertStringEndsWith('.wav', $asset->temporary_path);
            $this->assertFileDoesNotExist($workerFile);
        } finally {
            @unlink($workerFile);
        }
    }

    public function test_normalizes_cached_browser_flac_to_pcm_wav(): void
    {
        Storage::fake('local');
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'cached-flac',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/cached-flac',
            'idempotency_key' => 'soundfresh:cached-flac:soundon', 'release_title' => 'Cached FLAC',
            'artist_name' => 'Artist', 'track_count' => 1,
        ]);
        $relative = 'automation/downloads/Cached FLAC - Artist/Cached FLAC-Artist.flac';
        $absolute = Storage::disk('local')->path($relative);
        if (! is_dir(dirname($absolute))) mkdir(dirname($absolute), 0700, true);
        (new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=0.1', '-c:a', 'flac', $absolute]))->mustRun();
        $asset = $job->assets()->create([
            'type' => 'audio', 'track_position' => 1, 'temporary_path' => $relative,
            'original_filename' => basename($relative), 'file_size' => filesize($absolute),
        ]);

        $normalized = app(AssetDownloader::class)->normalizeAudioMaster($asset, $job);

        $this->assertStringEndsWith('.wav', $normalized->temporary_path);
        $this->assertSame('audio/wav', $normalized->mime_type);
        $this->assertFileExists(Storage::disk('local')->path($normalized->temporary_path));
        $this->assertFileDoesNotExist($absolute);
    }

    public function test_trims_tiktok_audio_clip_to_maximum_59_seconds(): void
    {
        Storage::fake('local');
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id, 'soundfresh_release_id' => 'long-clip',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/long-clip',
            'idempotency_key' => 'soundfresh:long-clip:soundon', 'release_title' => 'Long Clip',
            'artist_name' => 'Artist', 'track_count' => 1,
        ]);
        $relative = 'automation/downloads/Long Clip - Artist/Long Clip-Artist-trim.wav';
        $absolute = Storage::disk('local')->path($relative);
        if (! is_dir(dirname($absolute))) mkdir(dirname($absolute), 0700, true);
        (new Process(['ffmpeg', '-y', '-v', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=60.5', '-c:a', 'pcm_s16le', '-ar', '44100', $absolute]))->mustRun();
        $asset = $job->assets()->create([
            'type' => 'tiktok_audio', 'track_position' => 1, 'temporary_path' => $relative,
            'original_filename' => basename($relative), 'file_size' => filesize($absolute),
        ]);

        $normalized = app(AssetDownloader::class)->normalizeTikTokAudioClip($asset, $job);
        $validation = app(AssetValidator::class)->audio(Storage::disk('local')->path($normalized->temporary_path));

        $this->assertLessThanOrEqual(59.01, $validation['duration']);
        $this->assertGreaterThan(58.9, $validation['duration']);
        $this->assertSame('audio/wav', $normalized->mime_type);
    }
}
