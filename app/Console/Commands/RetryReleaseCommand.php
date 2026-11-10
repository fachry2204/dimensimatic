<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ReleaseJobStatus;
use App\Enums\AutomationRunStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\ReleaseJob;
use Illuminate\Console\Command;

final class RetryReleaseCommand extends Command
{
    protected $signature = 'soundon:retry {releaseJobId}';

    protected $description = 'Retry one failed or needs-attention release job from its checkpoint';

    public function handle(): int
    {
        $job = ReleaseJob::with('automationRun')->find((string) $this->argument('releaseJobId'));
        if (! $job || ! in_array($job->status, [ReleaseJobStatus::Failed, ReleaseJobStatus::NeedsAttention, ReleaseJobStatus::ManualAuthRequired], true)) {
            $this->error('Release job is missing or not retryable.');

            return self::INVALID;
        }
        $job->update(['status' => ReleaseJobStatus::Queued, 'error_code' => null, 'error_message' => null, 'finished_at' => null]);
        $job->automationRun->update(['status' => AutomationRunStatus::Running, 'stop_requested_at' => null, 'finished_at' => null]);
        ProcessReleaseJob::dispatch($job->id);
        $this->info('Release job queued from checkpoint '.$job->checkpoint->value.'.');

        return self::SUCCESS;
    }
}
