<?php

namespace Tests\Feature;

use App\Actions\Automation\PrepareReleaseAssets;
use App\Models\AutomationRun;
use App\Models\ReleaseAsset;
use App\Models\ReleaseJob;
use App\Services\Automation\AssetDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class PrepareReleaseAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_reuses_existing_temporary_asset_without_redownloading(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => '1', 'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/1', 'idempotency_key' => 'soundfresh:1:soundon']);
        $path = 'automation/downloads/'.$job->id.'/cover.png';
        Storage::disk('local')->put($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        ReleaseAsset::create(['release_job_id' => $job->id, 'type' => 'cover', 'temporary_path' => $path, 'original_filename' => 'cover.png']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('COVER_INVALID');
        app(PrepareReleaseAssets::class)->handle($job, ['cover' => ['url' => 'https://evil.example/cover.png', 'filename' => 'cover.png'], 'tracks' => []]);
    }

    public function test_reuses_validation_for_an_unchanged_cached_asset(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'cached',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/cached',
            'idempotency_key' => 'soundfresh:cached:soundon',
            'release_title' => 'Cached Release',
            'artist_name' => 'Cached Artist',
        ]);
        $path = 'automation/downloads/Cached Release - Cached Artist/Cached Release-Cached Artist.png';
        $contents = 'already-validated-cover';
        Storage::disk('local')->put($path, $contents);
        ReleaseAsset::create([
            'release_job_id' => $job->id,
            'type' => 'cover',
            'temporary_path' => $path,
            'original_filename' => 'Cached Release-Cached Artist.png',
            'file_size' => strlen($contents),
            'validation_json' => ['width' => 3000, 'height' => 3000],
        ]);

        $result = app(PrepareReleaseAssets::class)->handle($job, [
            'cover' => [],
            'tracks' => [],
        ]);

        $this->assertSame(['width' => 3000, 'height' => 3000], $result['cover']['validation']);
        Http::assertNothingSent();
    }

    public function test_records_realtime_asset_download_progress(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => 'progress',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/progress',
            'idempotency_key' => 'soundfresh:progress:soundon',
            'release_title' => 'Progress Release',
            'artist_name' => 'Progress Artist',
        ]);
        $path = 'automation/downloads/Progress Release - Progress Artist/Progress Release-Progress Artist.png';
        $contents = 'validated-cover';
        Storage::disk('local')->put($path, $contents);
        ReleaseAsset::create([
            'release_job_id' => $job->id,
            'type' => 'cover',
            'temporary_path' => $path,
            'original_filename' => 'Progress Release-Progress Artist.png',
            'file_size' => strlen($contents),
            'validation_json' => ['width' => 3000, 'height' => 3000],
        ]);

        app(PrepareReleaseAssets::class)->handle($job, ['cover' => [], 'tracks' => []]);

        $progress = $job->fresh()->asset_progress_json;
        $this->assertSame('completed', $progress['cover']['status']);
        $this->assertSame('pending', $progress['audio']['status']);
        $this->assertSame('unavailable', $progress['tiktok_audio']['status']);
    }

    public function test_builds_human_readable_release_asset_paths(): void
    {
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '2',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/2',
            'idempotency_key' => 'soundfresh:2:soundon',
            'release_title' => 'Judul: Rilis?',
            'artist_name' => 'Nama/Artis',
            'track_count' => 1,
        ]);
        $downloader = app(AssetDownloader::class);

        $this->assertSame(
            'automation/downloads/Judul- Rilis - Nama-Artis/Judul- Rilis-Nama-Artis.wav',
            $downloader->relativePath($job, 'audio', 'wav', 1),
        );
        $this->assertSame(
            'automation/downloads/Judul- Rilis - Nama-Artis/Judul- Rilis-Nama-Artis-trim.wav',
            $downloader->relativePath($job, 'tiktok_audio', 'wav', 1),
        );
        $this->assertSame(
            'automation/downloads/Judul- Rilis - Nama-Artis/Judul- Rilis-Nama-Artis.png',
            $downloader->relativePath($job, 'cover', 'png'),
        );
    }

    public function test_relocates_legacy_uuid_asset_to_named_release_folder(): void
    {
        Storage::fake('local');
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create([
            'automation_run_id' => $run->id,
            'soundfresh_release_id' => '3',
            'soundfresh_release_url' => 'https://cms.soundfresh.id/admin/releases/3',
            'idempotency_key' => 'soundfresh:3:soundon',
            'release_title' => 'My Release',
            'artist_name' => 'My Artist',
            'track_count' => 1,
        ]);
        $legacy = 'automation/downloads/'.$job->id.'/random-uuid.wav';
        Storage::disk('local')->put($legacy, 'audio-content');
        $asset = ReleaseAsset::create([
            'release_job_id' => $job->id,
            'type' => 'audio',
            'track_position' => 1,
            'temporary_path' => $legacy,
            'original_filename' => 'random-uuid.wav',
        ]);

        $asset = app(AssetDownloader::class)->relocateToNamedPath($asset, $job);

        $expected = 'automation/downloads/My Release - My Artist/My Release-My Artist.wav';
        $this->assertSame($expected, $asset->temporary_path);
        $this->assertSame('My Release-My Artist.wav', $asset->original_filename);
        Storage::disk('local')->assertExists($expected);
        Storage::disk('local')->assertMissing($legacy);
    }
}
