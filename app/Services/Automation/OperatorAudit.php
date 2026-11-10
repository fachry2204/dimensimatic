<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\AutomationEvent;

final class OperatorAudit
{
    public function record(string $event, string $message, ?string $runId = null, ?string $jobId = null, array $context = []): void
    {
        AutomationEvent::create(['automation_run_id' => $runId, 'release_job_id' => $jobId, 'level' => 'info', 'event' => $event, 'message' => $message, 'context_json' => ['operator_id' => auth()->id(), ...$context]]);
    }
}
