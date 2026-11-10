<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class PublishRelease extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['soundon_published_at' => 'datetime', 'cms_uploaded_at' => 'datetime', 'collected_at' => 'datetime', 'progress_percent' => 'integer'];
    }
    public function syncWorkerProgress(array $snapshot, string $attempt): void
    {
        if (! isset($snapshot['percent'], $snapshot['message']) || ! is_numeric($snapshot['percent']) || ! is_string($snapshot['message'])) return;
        // Stale polling responses cannot overwrite a newer attempt or a CMS/success checkpoint.
        $percent = max(0, min(85, (int) $snapshot['percent']));
        static::whereKey($this->id)->where('status', 'publishing')->where('progress_attempt', $attempt)
            ->where('progress_percent', '<=', $percent)->update([
                'progress_percent' => $percent,
                'progress_message' => mb_substr($snapshot['message'], 0, 255),
            ]);
    }
}
