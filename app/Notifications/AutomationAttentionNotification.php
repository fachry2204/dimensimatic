<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

final class AutomationAttentionNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $event, private readonly string $message, private readonly ?string $runId = null, private readonly ?string $jobId = null, private readonly ?string $errorCode = null) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return ['event' => $this->event, 'message' => mb_substr($this->message, 0, 500), 'run_id' => $this->runId, 'job_id' => $this->jobId, 'error_code' => $this->errorCode];
    }
}
