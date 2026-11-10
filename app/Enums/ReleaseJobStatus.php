<?php

declare(strict_types=1);

namespace App\Enums;

enum ReleaseJobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Stopped = 'stopped';
    case NeedsAttention = 'needs_attention';
    case Failed = 'failed';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case ManualAuthRequired = 'manual_auth_required';
}
