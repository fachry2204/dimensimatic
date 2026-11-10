<?php

namespace App\Services\Automation;

use Illuminate\Queue\DatabaseQueue;

class RetryingDatabaseQueue extends DatabaseQueue
{
    public function pop($queue = null)
    {
        if ($this->database->getDriverName() !== 'sqlite') {
            return parent::pop($queue);
        }

        // SQLite on PHP < 8.4 uses deferred transactions. Two consumers can
        // read the same candidate before either reserves it. Retry the whole
        // transaction on a lock collision; this is not a failed release job.
        $queue = $this->getQueue($queue);
        return $this->database->transaction(function () use ($queue) {
            $record = $this->getNextAvailableJob($queue);
            return $record ? $this->marshalJob($queue, $record) : null;
        }, 5);
    }
}
