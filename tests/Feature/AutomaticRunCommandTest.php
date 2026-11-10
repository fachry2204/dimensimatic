<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\CollectPendingSoundfreshReleases;
use App\Jobs\QueueSoundOnStatusChecks;
use App\Models\AutomationSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AutomaticRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_run_queues_release_collection_and_status_check_when_due(): void
    {
        Queue::fake();
        $this->travelTo(now()->setTime(9, 0));
        AutomationSetting::create([
            'automatic_run_enabled' => true,
            'automatic_run_interval_minutes' => 30,
            'automatic_run_start_time' => '08:00',
            'automatic_run_end_time' => '22:00',
            'automatic_run_last_started_at' => now()->subMinutes(31),
        ]);

        $this->artisan('dimensimatic:auto-run')->assertExitCode(0);

        Queue::assertPushed(CollectPendingSoundfreshReleases::class);
        Queue::assertPushed(QueueSoundOnStatusChecks::class, fn (QueueSoundOnStatusChecks $job): bool => $job->sourceTab === 'both');
        $this->assertNotNull(AutomationSetting::current()->automatic_run_last_started_at);
    }

    public function test_automatic_run_skips_outside_active_hours(): void
    {
        Queue::fake();
        $this->travelTo(now()->setTime(23, 0));
        AutomationSetting::create([
            'automatic_run_enabled' => true,
            'automatic_run_interval_minutes' => 30,
            'automatic_run_start_time' => '08:00',
            'automatic_run_end_time' => '22:00',
            'automatic_run_last_started_at' => now()->subMinutes(31),
        ]);

        $this->artisan('dimensimatic:auto-run')->assertExitCode(0);

        Queue::assertNothingPushed();
    }

    public function test_automatic_run_respects_interval(): void
    {
        Queue::fake();
        $this->travelTo(now()->setTime(9, 0));
        AutomationSetting::create([
            'automatic_run_enabled' => true,
            'automatic_run_interval_minutes' => 30,
            'automatic_run_start_time' => '08:00',
            'automatic_run_end_time' => '22:00',
            'automatic_run_last_started_at' => now()->subMinutes(10),
        ]);

        $this->artisan('dimensimatic:auto-run')->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
