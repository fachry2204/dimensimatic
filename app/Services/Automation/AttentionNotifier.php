<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Models\User;
use App\Notifications\AutomationAttentionNotification;
use Illuminate\Support\Facades\Notification;

final class AttentionNotifier
{
    public function send(string $event, string $message, ?string $runId = null, ?string $jobId = null, ?string $errorCode = null): void
    {
        $recipients = User::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->whereIn('name', ['Admin', 'Operator']))->get();
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new AutomationAttentionNotification($event, $message, $runId, $jobId, $errorCode));
        }
    }
}
