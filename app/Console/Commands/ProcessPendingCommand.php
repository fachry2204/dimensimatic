<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Automation\StartAutomationRun;
use Illuminate\Console\Command;

final class ProcessPendingCommand extends Command
{
    protected $signature = 'soundon:process-pending {--limit=100}';

    protected $description = 'Process CMS pending releases into SoundOn drafts';

    public function handle(StartAutomationRun $start): int
    {
        $run = $start->handle(null, (int) $this->option('limit'));
        $this->info("Automation run {$run->id}: {$run->status->value}");

        return self::SUCCESS;
    }
}
