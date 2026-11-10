<?php

namespace Tests\Feature;

use App\Enums\Platform;
use App\Exceptions\BrowserWorkerException;
use App\Models\AutomationAccount;
use App\Services\Automation\SessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SessionReuseTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $platform = 'soundon'): AutomationAccount
    {
        return AutomationAccount::create(['platform' => $platform, 'name' => $platform, 'status' => 'active',
            'email_encrypted' => 'operator@example.com', 'password_encrypted' => 'password',
            'session_state_encrypted' => ['cookies' => [], 'origins' => []],
            'last_authenticated_at' => now()->subDay()]);
    }

    public function test_valid_session_is_refreshed_and_reused_without_login(): void
    {
        $this->account();
        $state = ['cookies' => [], 'origins' => [], 'sessionStorage' => [['origin' => 'https://www.soundon.global', 'values' => ['token' => 'renewed']]]];
        Http::fake(['*' => Http::response(['data' => ['valid' => true, 'session_state' => $state]])]);
        $sessions = app(SessionManager::class);
        $this->assertSame($state, $sessions->ensure(Platform::SoundOn)->session_state_encrypted);
        $this->assertSame($state, $sessions->ensure(Platform::SoundOn)->session_state_encrypted);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/sessions/login'));
    }

    public function test_inconclusive_validation_preserves_session_and_does_not_login(): void
    {
        $account = $this->account();
        Http::fake(['*' => Http::response(['data' => []])]);
        try {
            app(SessionManager::class)->ensure(Platform::SoundOn);
            $this->fail('Expected an inconclusive validation error.');
        } catch (BrowserWorkerException $error) {
            $this->assertSame('session_validation_failed', $error->reason);
        }
        $this->assertSame($account->session_state_encrypted, $account->fresh()->session_state_encrypted);
        Http::assertSentCount(1);
    }

    public function test_rejected_session_logs_in_once_then_reuses_new_session(): void
    {
        $this->account();
        Http::fake([
            '*/sessions/validate' => Http::response(['data' => ['valid' => false]]),
            '*/sessions/login' => Http::response(['data' => ['session_state' => ['cookies' => [], 'origins' => []]]]),
        ]);
        $sessions = app(SessionManager::class);
        $this->assertSame('active', $sessions->ensure(Platform::SoundOn)->status);
        $sessions->ensure(Platform::SoundOn);
        Http::assertSentCount(2);
    }

    public function test_rechecking_one_platform_preserves_both_saved_sessions(): void
    {
        $soundon = $this->account();
        $cms = $this->account('soundfresh');
        app(SessionManager::class)->requireValidation(Platform::SoundOn);
        $this->assertSame('expired', $soundon->fresh()->status);
        $this->assertNotNull($soundon->fresh()->session_state_encrypted);
        $this->assertSame('active', $cms->fresh()->status);
        $this->assertNotNull($cms->fresh()->session_state_encrypted);
    }
}
