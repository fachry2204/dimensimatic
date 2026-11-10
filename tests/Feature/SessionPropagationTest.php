<?php

namespace Tests\Feature;

use App\Services\Automation\PlaywrightClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SessionPropagationTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_state_is_signed_and_sent_to_pending_endpoint(): void
    {
        config(['automation.hmac_key' => 'test-secret', 'automation.worker_url' => 'http://127.0.0.1:3100']);
        Http::fake(['*' => Http::response(['success' => true, 'data' => ['items' => []]])]);
        $state = ['cookies' => [['name' => 'session', 'value' => 'redacted']], 'origins' => []];
        app(PlaywrightClient::class)->pending(1, $state);
        Http::assertSent(fn ($request) => $request['session_state'] === $state && $request->hasHeader('X-Automation-Signature'));
    }
}
