<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AutomationAccount;
use App\Services\Automation\AttentionNotifier;
use App\Services\Automation\PlaywrightClient;
use Illuminate\Console\Command;
use Throwable;

final class SessionHealthCommand extends Command
{
    protected $signature = 'soundon:session:health';

    protected $description = 'Validate existing cached sessions without performing login';

    public function handle(PlaywrightClient $worker, AttentionNotifier $notifier): int
    {
        $failed = false;
        foreach (AutomationAccount::whereNotNull('session_state_encrypted')->get() as $account) {
            try {
                $valid = ($worker->validateSession($account->platform->value, $account->session_state_encrypted)['valid'] ?? false) === true;
                $account->update(['status' => $valid ? 'active' : 'expired']);
                if (! $valid) {
                    $notifier->send('session_expired', 'Cached platform session has expired.', errorCode: 'AUTH_SESSION_EXPIRED');
                }
                $this->line($account->platform->value.': '.($valid ? 'valid' : 'expired'));
                $failed = $failed || ! $valid;
            } catch (Throwable $e) {
                report($e);
                $this->error($account->platform->value.': health check failed');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
