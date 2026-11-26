<?php

namespace Tests\Feature;

use App\Services\Automation\LocalSystemManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class LocalSystemDisplayStatusTest extends TestCase
{
    public function test_display_reuses_snapshot_but_worker_controls_invalidate_it(): void
    {
        // Separate the test cache namespace from a running desktop instance.
        config(['automation.local_server_url' => config('automation.local_server_url').'-test']);
        $key = 'local-system-display:'.sha1(base_path().'|'.config('automation.local_server_url').'|'.config('automation.worker_url'));
        $snapshot = ['worker' => true, 'release_workers' => 42];
        $manager = app(LocalSystemManager::class);

        try {
            Cache::store('file')->put($key, $snapshot, 5);
            app()->instance('env', 'local');
            $this->assertSame($snapshot, $manager->displayStatus());

            app()->instance('env', 'testing');
            $this->assertSame(0, $manager->status()['release_workers']);
            $manager->startQueueWorkers();
            $this->assertNull(Cache::store('file')->get($key));
        } finally {
            app()->instance('env', 'testing');
            Cache::store('file')->forget($key);
        }
    }
}
