<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class BrowserWorkerException extends RuntimeException
{
    public function __construct(
        public readonly string $reason = 'worker_error',
        string $message = 'Browser worker request failed.',
    ) {
        parent::__construct($message);
    }
}
