<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case CompletedNoPending = 'completed_no_pending';
    case Failed = 'failed';
    case Stopped = 'stopped';
    case Cancelled = 'cancelled';
    case ManualAuthRequired = 'manual_auth_required';
}
