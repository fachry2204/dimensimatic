<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Services\Automation\SessionManager;
use Illuminate\Console\Command;

final class SessionLoginCommand extends Command
{
    protected $signature = 'soundon:session:login {platform : soundfresh or soundon}';

    protected $description = 'Refresh an automation platform session securely';

    public function handle(SessionManager $sessions): int
    {
        $platform = Platform::tryFrom((string) $this->argument('platform'));
        if (! $platform) {
            $this->error('Platform must be soundfresh or soundon.');

            return self::INVALID;
        }

        $sessions->clear($platform);
        $account = $sessions->ensure($platform);
        $this->line($platform->value.': '.$account->status);

        return $account->status === 'active' ? self::SUCCESS : self::FAILURE;
    }
}
