<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Platform;
use App\Models\AutomationAccount;
use App\Models\AutomationArtifact;
use App\Services\Automation\PlaywrightClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class DiscoverSelectorsCommand extends Command
{
    protected $signature = 'soundon:selectors:discover {platform} {url}';

    protected $description = 'Capture a sanitized control inventory for authenticated selector discovery';

    public function handle(PlaywrightClient $worker): int
    {
        $platform = Platform::tryFrom((string) $this->argument('platform'));
        if (! $platform) {
            $this->error('Platform must be soundfresh or soundon.');

            return self::INVALID;
        }

        $account = AutomationAccount::where('platform', $platform)->first();
        if (! $account?->session_state_encrypted) {
            $this->error('No cached session. Run soundon:session:login first.');

            return self::FAILURE;
        }

        $result = $worker->discoverSelectors($platform->value, $account->session_state_encrypted, (string) $this->argument('url'));
        $path = 'automation/traces/selectors-'.$platform->value.'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        AutomationArtifact::create(['type' => 'html_snapshot', 'path' => $path, 'expires_at' => now()->addDays(7), 'created_at' => now()]);
        $this->info('Sanitized selector inventory saved to private storage.');

        return self::SUCCESS;
    }
}
