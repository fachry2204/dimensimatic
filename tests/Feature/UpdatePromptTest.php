<?php

namespace Tests\Feature;

use App\Actions\Automation\StopWorkForUpdate;
use App\Livewire\Automation\ApplicationUpdatePrompt;
use App\Models\AutomationRun;
use App\Models\AutomationSetting;
use App\Models\PublishRelease;
use App\Models\ReleaseJob;
use App\Models\User;
use App\Services\Automation\UpdateBackup;
use App\Services\Automation\UpdateReleaseFeed;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class UpdatePromptTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        return $admin;
    }

    private function release(?string $digest = null): array
    {
        return ['tag_name' => 'v9.9.9', 'assets' => [[
            'name' => 'Dimensimatic-Setup-v9.9.9.exe',
            'browser_download_url' => 'https://github.com/fachry2204/dimensimatic/releases/download/v9.9.9/Dimensimatic-Setup-v9.9.9.exe',
            'digest' => $digest,
        ]]];
    }

    public function test_notification_uses_cached_release_and_dismissal_has_no_worker_side_effect(): void
    {
        $this->admin();
        $this->mock(UpdateReleaseFeed::class, fn ($m) => $m->shouldReceive('snapshot')->andReturn(['release' => $this->release()]));
        $this->mock(StopWorkForUpdate::class, fn ($m) => $m->shouldNotReceive('handle'));
        Http::fake();
        $prompt = Livewire::test(ApplicationUpdatePrompt::class)->call('refreshNotification')
            ->assertSet('showModal', true)->assertSee('menghentikan semua pekerjaan')
            ->call('dismiss')->assertSet('showModal', false)
            ->call('refreshNotification')->assertSet('showModal', false);
        Http::assertNothingSent();
    }

    public function test_confirmation_is_required_and_bad_checksum_never_stops_work(): void
    {
        $this->admin();
        Http::fake(['*releases/latest' => Http::response($this->release('sha256:'.str_repeat('0', 64))), '*9.9.9.exe' => Http::response('downloaded bytes')]);
        $this->mock(StopWorkForUpdate::class, fn ($m) => $m->shouldNotReceive('handle'));
        $this->mock(UpdateBackup::class, fn ($m) => $m->shouldNotReceive('capture'));
        Livewire::test(ApplicationUpdatePrompt::class)->call('openConfirmation')
            ->call('downloadAndInstall')->assertSee('Konfirmasi diperlukan')
            ->call('downloadAndInstall', true)->assertSee('Checksum installer tidak cocok')
            ->assertSet('downloadReady', false);
    }

    public function test_download_failure_keeps_workers_running(): void
    {
        $this->admin();
        Http::fake(['*releases/latest' => Http::response($this->release()), '*9.9.9.exe' => Http::response('unavailable', 503)]);
        $this->mock(StopWorkForUpdate::class, fn ($m) => $m->shouldNotReceive('handle'));
        Livewire::test(ApplicationUpdatePrompt::class)->call('openConfirmation')->call('downloadAndInstall', true)
            ->assertSet('downloadReady', false)->assertSee('Pekerjaan belum dihentikan');
    }

    public function test_stop_for_update_preserves_completed_work_and_disables_scheduling(): void
    {
        $this->admin();
        AutomationSetting::current()->update(['automatic_run_enabled' => true]);
        $run = AutomationRun::create(['status' => 'running']);
        $job = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'update-test', 'soundfresh_release_url' => 'https://example.test/release', 'idempotency_key' => 'update-test', 'status' => 'running', 'soundon_check_status' => 'checking', 'soundfresh_verify_status' => 'sending']);
        $done = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'update-done', 'soundfresh_release_url' => 'https://example.test/done', 'idempotency_key' => 'update-done', 'status' => 'completed']);
        $publish = PublishRelease::create(['draft_id' => 'update-test', 'draft_url' => 'https://example.test/draft', 'title' => 'Test', 'artist' => 'Test', 'status' => 'publishing']);
        foreach (['release-automation', 'status-checks', 'cms-verification', 'default'] as $queue) DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()]);
        app(StopWorkForUpdate::class)->handle();
        $this->assertFalse(AutomationSetting::current()->automatic_run_enabled);
        $this->assertSame('stopped', $run->fresh()->status->value);
        $this->assertSame('stopped', $job->fresh()->status->value);
        $this->assertSame('stopped', $job->fresh()->soundon_check_status);
        $this->assertSame('failed', $job->fresh()->soundfresh_verify_status);
        $this->assertSame('completed', $done->fresh()->status->value);
        $this->assertSame('failed', $publish->fresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_sqlite_snapshot_captures_committed_data_and_admin_password_survives_bootstrap(): void
    {
        $this->admin();
        $user = User::factory()->create(['username' => 'admin', 'password' => 'preserved-secret']);
        $hash = $user->password;
        $this->artisan('dimensimatic:bootstrap')->assertSuccessful();
        $this->assertSame($hash, $user->fresh()->password);
        $source = new \PDO('sqlite::memory:');
        $source->exec('CREATE TABLE proof (value TEXT); INSERT INTO proof VALUES ("preserved")');
        $target = storage_path('logs/update-snapshot-test-'.bin2hex(random_bytes(4)).'.sqlite');
        try {
            app(UpdateBackup::class)->snapshotDatabase($source, $target);
            $backup = new \PDO('sqlite:'.$target);
            $this->assertSame('preserved', $backup->query('SELECT value FROM proof')->fetchColumn());
            $backup = null;
        } finally { @unlink($target); }
    }
}
