<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Automation\CleanupTemporaryAssets;
use App\Models\AutomationArtifact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

final class CleanupAutomationCommand extends Command
{
    protected $signature = 'soundon:cleanup';

    protected $description = 'Delete expired temporary assets and artifacts';

    public function handle(CleanupTemporaryAssets $cleanup): int
    {
        $assets = $cleanup->handle(expiredOnly: true);
        $artifacts = 0;
        AutomationArtifact::where('expires_at', '<', now())->each(function ($artifact) use (&$artifacts) {
            Storage::disk('local')->delete($artifact->path);
            $artifact->delete();
            $artifacts++;
        });
        $this->info("Deleted {$assets} assets and {$artifacts} artifacts.");

        return self::SUCCESS;
    }
}
