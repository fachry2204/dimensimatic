<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReleaseCheckpoint;
use App\Enums\ReleaseJobStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReleaseJob extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ReleaseJobStatus::class, 'checkpoint' => ReleaseCheckpoint::class, 'metadata_snapshot_json' => 'array', 'asset_progress_json' => 'array', 'soundon_isrcs_json' => 'array', 'soundon_status_checked_at' => 'datetime', 'soundon_check_started_at' => 'datetime', 'soundon_check_finished_at' => 'datetime', 'soundfresh_verified_at' => 'datetime', 'soundfresh_rejected_at' => 'datetime', 'started_at' => 'datetime', 'finished_at' => 'datetime', 'progress_updated_at' => 'datetime'];
    }

    /** @return array{upc: string, isrcs: list<string>}|null */
    public function cmsVerificationIdentifiers(): ?array
    {
        if ($this->soundfresh_workflow_status !== 'uploading'
            || ! in_array($this->soundon_release_status, ['approved', 'delivery', 'live'], true)) {
            return null;
        }
        $upc = trim((string) $this->soundon_upc);
        $isrcs = array_values(array_map(static fn ($value): string => is_string($value) ? strtoupper(trim($value)) : '', (array) $this->soundon_isrcs_json));
        if (! preg_match('/^\d{12,13}$/', $upc) || count($isrcs) !== max(1, (int) $this->track_count)) {
            return null;
        }
        foreach ($isrcs as $isrc) {
            if (! preg_match('/^[A-Z]{2}[A-Z0-9]{3}\d{7}$/', $isrc)) {
                return null;
            }
        }

        return ['upc' => $upc, 'isrcs' => $isrcs];
    }

    public function automationRun(): BelongsTo
    {
        return $this->belongsTo(AutomationRun::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(ReleaseAsset::class);
    }
}
