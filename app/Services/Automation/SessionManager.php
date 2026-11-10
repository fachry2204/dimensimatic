<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\Platform;
use App\Exceptions\BrowserWorkerException;
use App\Exceptions\CredentialsNotConfiguredException;
use App\Models\AutomationAccount;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;

final class SessionManager
{
    public function __construct(private readonly PlaywrightClient $worker) {}

    public function ensure(Platform $platform): AutomationAccount
    {
        return Cache::lock('automation-session-'.$platform->value, 300)->block(240, fn () => $this->ensureLocked($platform));
    }

    private function ensureLocked(Platform $platform): AutomationAccount
    {
        $account = AutomationAccount::firstOrCreate(['platform' => $platform], ['name' => $platform === Platform::Soundfresh ? \App\Support\SystemBranding::text('Dimensi Suara') : 'SoundOn', 'status' => 'expired']);
        $this->assertReadable($platform, $account);
        $validationInterval = max(0, (int) config('automation.session_validation_interval_seconds', 300));
        if (
            $account->session_state_encrypted
            && $account->status === 'active'
            && $account->last_authenticated_at?->isAfter(now()->subSeconds($validationInterval))
        ) {
            return $account;
        }

        if ($account->session_state_encrypted) {
            try {
                $result = $this->worker->validateSession($platform->value, $account->session_state_encrypted);
            } catch (\Throwable) {
                throw new BrowserWorkerException('session_validation_failed', "Browser worker failed while validating {$platform->value} session.");
            }
            if (($result['valid'] ?? false) === true) {
                $account->update(['status' => 'active', 'last_authenticated_at' => now(),
                    'session_state_encrypted' => $result['session_state'] ?? $account->session_state_encrypted]);

                return $account;
            }
            if (($result['valid'] ?? null) !== false) {
                throw new BrowserWorkerException('session_validation_failed', 'Session validation did not return a conclusive result.');
            }
            $account->update(['session_state_encrypted' => null, 'session_expires_at' => null, 'status' => 'expired']);
        }
        $email = (string) ($account->email_encrypted ?? '');
        $password = (string) ($account->password_encrypted ?? '');
        if ($email === '' || $password === '') {
            throw new CredentialsNotConfiguredException($platform);
        }
        try {
            $result = $this->worker->login($platform->value, $email, $password);
        } catch (RequestException $error) {
            $workerCode = $error->response?->json('error');
            if ($error->response?->status() === 401 && in_array($workerCode, ['AUTH_INVALID_CREDENTIALS', 'AUTH_LOGIN_FAILED'], true)) {
                $account->update(['status' => 'authentication_failed']);
                throw new BrowserWorkerException('invalid_credentials', "{$platform->value} rejected the stored credentials.");
            }

            throw new BrowserWorkerException('authentication_failed', "Browser worker failed while authenticating {$platform->value}.");
        } catch (ConnectionException) {
            throw new BrowserWorkerException('worker_unavailable', 'Browser worker is unavailable.');
        } catch (\Throwable) {
            throw new BrowserWorkerException('authentication_failed', "Browser worker failed while authenticating {$platform->value}.");
        }
        if (($result['manual_auth_required'] ?? false) === true) {
            $account->update(['status' => 'manual_auth_required']);

            return $account;
        }
        if (! is_array($result['session_state'] ?? null)) {
            throw new BrowserWorkerException('authentication_failed', 'Login did not return a reusable session.');
        }
        $account->update(['session_state_encrypted' => $result['session_state'], 'session_expires_at' => now()->addHours(8), 'last_authenticated_at' => now(), 'status' => 'active']);

        return $account;
    }

    public function assertReadable(Platform $platform, ?AutomationAccount $account = null): void
    {
        $account ??= AutomationAccount::query()->where('platform', $platform)->first();
        if (! $account) {
            throw new CredentialsNotConfiguredException($platform);
        }

        try {
            $email = $account->email_encrypted;
            $password = $account->password_encrypted;
            $session = $account->session_state_encrypted;
        } catch (DecryptException) {
            // Keep the original ciphertext intact for recovery with the old key.
            $account->update(['status' => 'credentials_need_reentry']);
            throw new CredentialsNotConfiguredException($platform, true);
        }

        if ($session === null && (blank($email) || blank($password))) {
            throw new CredentialsNotConfiguredException($platform);
        }
    }

    public function clear(Platform $platform): void
    {
        AutomationAccount::where('platform', $platform)->update(['session_state_encrypted' => null, 'session_expires_at' => null, 'status' => 'expired']);
    }

    public function requireValidation(Platform $platform): void
    {
        AutomationAccount::where('platform', $platform)->update(['status' => 'expired', 'last_authenticated_at' => null]);
    }
}
