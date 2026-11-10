<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\AutomationAccount;
use App\Services\Automation\PlaywrightClient;
use Illuminate\Console\Command;

final class SessionCheckCommand extends Command
{
    protected $signature = 'soundon:session:check';

    protected $description = 'Validate cached sessions without printing session data';

    public function handle(PlaywrightClient $worker): int
    {
        foreach (Platform::cases() as $platform) {
            $account = AutomationAccount::where('platform', $platform)->first();
            $result = $worker->validateSession($platform->value, $account?->session_state_encrypted);
            $valid = ($result['valid'] ?? false) === true;
            $account?->update(['status' => $valid ? 'active' : 'expired']);
            $this->line($platform->value.': '.($valid ? 'valid' : 'invalid'));
        }

        return self::SUCCESS;
    }
}
