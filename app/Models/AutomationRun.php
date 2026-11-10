<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationRunStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationRun extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => AutomationRunStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'stop_requested_at' => 'datetime', 'summary_json' => 'array'];
    }

    public function releaseJobs(): HasMany
    {
        return $this->hasMany(ReleaseJob::class);
    }
}
