<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AutomationRunStatus;
use App\Jobs\CollectPendingSoundfreshReleases;
use App\Jobs\QueueSoundOnStatusChecks;
use App\Models\AutomationRun;
use App\Models\AutomationSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RunAutomaticAutomation extends Command
{
    protected $signature = 'dimensimatic:auto-run';

    protected $description = 'Run automatic CMS collection and SoundOn status checks when schedule settings allow it.';

    public function handle(): int
    {
        $setting = AutomationSetting::current();
        if (! $setting->automatic_run_enabled) {
            $this->components->info('Otomatis berjalan nonaktif.');

            return self::SUCCESS;
        }

        $now = now();
        if (! $this->withinActiveHours($now, (string) $setting->automatic_run_start_time, (string) $setting->automatic_run_end_time)) {
            $this->components->info('Di luar jam otomatis berjalan.');

            return self::SUCCESS;
        }

        $lastRun = $setting->automatic_run_last_started_at;
        if ($lastRun && $lastRun->diffInMinutes($now) < $setting->automatic_run_interval_minutes) {
            $this->components->info('Interval otomatis belum tercapai.');

            return self::SUCCESS;
        }

        $activeRunExists = AutomationRun::query()
            ->whereIn('status', [AutomationRunStatus::Queued, AutomationRunStatus::Running, AutomationRunStatus::Paused])
            ->exists();
        $queuedJobsExist = DB::table('jobs')->whereIn('queue', ['release-automation', 'status-checks'])->exists();
        if ($activeRunExists || $queuedJobsExist) {
            $this->components->info('Automation masih berjalan, siklus otomatis dilewati.');

            return self::SUCCESS;
        }

        $run = AutomationRun::query()->create([
            'triggered_by' => null,
            'status' => AutomationRunStatus::Queued,
            'started_at' => $now,
            'summary_json' => ['draft_only' => true, 'automatic_upload' => true, 'awaiting_selection' => false, 'collection_pending' => true, 'automatic_run' => true],
        ]);

        CollectPendingSoundfreshReleases::dispatch((string) $run->id);
        QueueSoundOnStatusChecks::dispatch(null, 'both');
        $setting->update(['automatic_run_last_started_at' => $now]);

        $this->components->info('Siklus otomatis diantrikan.');

        return self::SUCCESS;
    }

    private function withinActiveHours(Carbon $now, string $start, string $end): bool
    {
        $current = $now->format('H:i');
        $start = substr($start, 0, 5);
        $end = substr($end, 0, 5);

        if ($start === $end) {
            return true;
        }

        if ($start < $end) {
            return $current >= $start && $current <= $end;
        }

        return $current >= $start || $current <= $end;
    }
}
