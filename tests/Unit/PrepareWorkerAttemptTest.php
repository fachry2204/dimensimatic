<?php

namespace Tests\Unit;

use App\Services\Automation\PlaywrightClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PrepareWorkerAttemptTest extends TestCase
{
    public function test_prepare_only_resets_the_requested_release(): void
    {
        config(['automation.hmac_key' => 'test-key', 'automation.worker_url' => 'http://worker.test']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['cancelled' => false]])]);
        $this->assertFalse(app(PlaywrightClient::class)->prepareAttempt('release-123')['cancelled']);
        Http::assertSent(fn ($request) => $request->url() === 'http://worker.test/v1/jobs/release-123/prepare');
        Http::assertSentCount(1);
    }
}
