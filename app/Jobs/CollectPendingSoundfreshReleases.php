<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Automation\StartAutomationRun;
use App\Models\AutomationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class CollectPendingSoundfreshReleases implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 2700;

    public function __construct(public readonly string $automationRunId, public readonly ?int $limit = null)
    {
        $this->onQueue('release-automation');
    }

    public function handle(StartAutomationRun $starter): void
    {
        $run = AutomationRun::query()->find($this->automationRunId);
        if (! $run || $run->stop_requested_at || $run->status->value === 'cancelled') {
            return;
        }

        $starter->collect($run, $this->limit);
    }
}
