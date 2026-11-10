<?php

declare(strict_types=1);

namespace App\Actions\Automation;

use App\Enums\AutomationRunStatus;
use App\Enums\ReleaseJobStatus;
use App\Jobs\ProcessReleaseJob;
use App\Models\ReleaseJob;
use Illuminate\Support\Facades\DB;

final class UploadEligibleDraftsToSoundOn
{
    /** @param array<int, string> $releaseJobIds
     * @return array{queued:int, skipped:int}
     */
    public function handle(array $releaseJobIds): array
    {
        $requestedIds = collect($releaseJobIds)->map('strval')->filter()->unique()->values();
        if ($requestedIds->isEmpty()) {
            return ['queued' => 0, 'skipped' => 0];
        }

        $eligible = ReleaseJob::query()
            ->with('automationRun')
            ->whereIn('id', $requestedIds)
            ->whereIn('status', [ReleaseJobStatus::Queued, ReleaseJobStatus::Completed, ReleaseJobStatus::NeedsAttention, ReleaseJobStatus::Failed])
            ->whereNull('soundon_draft_id')
            ->where(function ($query): void {
                $query->whereNull('error_code')->orWhereNotIn('error_code', [
                    'DUPLICATE_CHECK_PENDING', 'DUPLICATE_CHECK_FAILED', 'DUPLICATE_RELEASE_FOUND',
                ]);
            })
            ->orderBy('metadata_snapshot_json->release_date')
            ->get();

        $skipped = $requestedIds->count() - $eligible->count();

        DB::transaction(function () use ($eligible): void {
            foreach ($eligible as $job) {
                $run = $job->automationRun;
                $summary = $run->summary_json ?? [];
                $summary['draft_upload_requested'] = true;
                $summary['awaiting_selection'] = false;
                $summary['selected_release_job_ids'] = collect($summary['selected_release_job_ids'] ?? [])
                    ->push((string) $job->id)
                    ->unique()
                    ->values()
                    ->all();
                $run->update([
                    'status' => AutomationRunStatus::Running,
                    'summary_json' => $summary,
                    'finished_at' => null,
                ]);
                $job->update([
                    'status' => ReleaseJobStatus::Queued,
                    'error_code' => null,
                    'error_message' => null,
                    'finished_at' => null,
                ]);
            }
        });

        foreach ($eligible as $job) {
            ProcessReleaseJob::dispatch($job->id);
        }

        return ['queued' => $eligible->count(), 'skipped' => $skipped];
    }
}
