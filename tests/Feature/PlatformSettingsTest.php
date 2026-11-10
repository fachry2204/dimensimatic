<?php

namespace Tests\Feature;

use App\Livewire\Automation\AccountSessions;
use App\Models\AutomationAccount;
use App\Models\AutomationRun;
use App\Models\AutomationSetting;
use App\Models\ReleaseAsset;
use App\Models\ReleaseJob;
use App\Models\User;
use App\Services\Automation\PlaywrightClient;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_unchanged_credentials_keeps_the_active_session(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $state = ['cookies' => [], 'origins' => []];
        $account = AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn',
            'email_encrypted' => 'operator@example.com', 'password_encrypted' => 'password',
            'session_state_encrypted' => $state, 'status' => 'active', 'last_authenticated_at' => now()]);
        Livewire::actingAs($admin)->test(AccountSessions::class)
            ->set('emails.soundon', 'operator@example.com')->set('passwords.soundon', '')
            ->call('saveCredentials', 'soundon')->assertHasNoErrors();
        $this->assertSame($state, $account->fresh()->session_state_encrypted);
        $this->assertSame('active', $account->fresh()->status);
        Livewire::test(AccountSessions::class)
            ->set('emails.soundon', 'operator@example.com')->set('passwords.soundon', 'new-password')
            ->call('saveCredentials', 'soundon')->assertHasNoErrors();
        $this->assertNull($account->fresh()->session_state_encrypted);
    }

    public function test_admin_can_change_cms_login_url_and_worker_uses_it(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $account = AutomationAccount::create([
            'platform' => 'soundfresh', 'name' => 'Dimensimatic', 'status' => 'active',
            'session_state_encrypted' => ['cookies' => []], 'session_expires_at' => now()->addHour(),
        ]);

        Livewire::actingAs($admin)->test(AccountSessions::class)
            ->assertSet('cmsLoginUrl', 'https://dimensisuara.id/login')
            ->assertSee('Dimensimatic')
            ->set('cmsLoginUrl', 'https://cms.example.com/sign-in')
            ->call('saveCmsLoginUrl')->assertHasNoErrors();

        $this->assertDatabaseHas('automation_settings', ['cms_login_url' => 'https://cms.example.com/sign-in']);
        $this->assertNull($account->fresh()->session_state_encrypted);
        $this->assertSame('expired', $account->fresh()->status);
        Livewire::test(AccountSessions::class)->assertSet('cmsLoginUrl', 'https://cms.example.com/sign-in');

        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);
        $worker = app(PlaywrightClient::class);
        $worker->login('soundfresh', 'operator@example.com', 'password');
        $worker->validateSession('soundfresh', []);
        $worker->pending();
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request['cms_login_url'] === 'https://cms.example.com/sign-in'
            && $request->hasHeader('X-Automation-Signature'));
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('https://cms.example.com/sign-in', $request['cms_login_url']);
        }
        $this->assertSame('https://cms.example.com', AutomationSetting::current()->cmsBaseUrl());
    }

    public function test_cms_login_url_rejects_invalid_or_non_https_addresses(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        foreach (['', 'invalid-url', 'javascript:alert(1)', 'http://cms.example.com/login', 'https://user:secret@cms.example.com/login'] as $url) {
            Livewire::actingAs($admin)->test(AccountSessions::class)
                ->set('cmsLoginUrl', $url)->call('saveCmsLoginUrl')->assertHasErrors('cmsLoginUrl');
        }
        $this->assertNull(AutomationSetting::current()->cms_login_url);
    }

    public function test_user_without_session_permission_cannot_change_cms_login_url(): void
    {
        Livewire::actingAs(User::factory()->create())->test(AccountSessions::class)
            ->set('cmsLoginUrl', 'https://cms.example.com/login')
            ->call('saveCmsLoginUrl')->assertForbidden();
        $this->assertNull(AutomationSetting::current()->cms_login_url);
    }

    public function test_admin_can_store_platform_credentials_encrypted(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)->set('emails.soundfresh', 'source@example.com')->set('passwords.soundfresh', 'private-password')->call('saveCredentials', 'soundfresh')->assertHasNoErrors();
        $account = AutomationAccount::where('platform', 'soundfresh')->firstOrFail();
        $this->assertSame('source@example.com', $account->email_encrypted);
        $this->assertSame('private-password', $account->password_encrypted);
        $raw = DB::table('automation_accounts')->where('id', $account->id)->first();
        $this->assertStringNotContainsString('source@example.com', $raw->email_encrypted);
        $this->assertStringNotContainsString('private-password', $raw->password_encrypted);
    }

    public function test_password_is_not_rendered_back_to_settings_page(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn', 'email_encrypted' => 'target@example.com', 'password_encrypted' => 'hidden-password', 'status' => 'expired']);
        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)->assertSet('emails.soundon', 'target@example.com')->assertSet('passwords.soundon', '')->assertDontSee('hidden-password');
    }

    public function test_restored_credentials_with_another_key_can_be_reentered(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $account = AutomationAccount::create(['platform' => 'soundfresh', 'name' => 'Dimensimatic', 'status' => 'active']);
        DB::table('automation_accounts')->where('id', $account->id)->update([
            'email_encrypted' => 'old-key-ciphertext',
            'password_encrypted' => 'old-key-ciphertext',
            'session_state_encrypted' => 'old-key-ciphertext',
        ]);

        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)
            ->assertSee('Masukkan ulang email dan password')
            ->set('emails.soundfresh', 'new@example.com')
            ->set('passwords.soundfresh', 'new-password')
            ->call('saveCredentials', 'soundfresh')
            ->assertHasNoErrors();

        $this->assertSame('new@example.com', $account->fresh()->email_encrypted);
        $this->assertSame('new-password', $account->fresh()->password_encrypted);
        $this->assertNull($account->fresh()->session_state_encrypted);
    }

    public function test_reentered_credentials_are_saved_and_logged_in_in_one_action(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $account = AutomationAccount::create(['platform' => 'soundon', 'name' => 'SoundOn', 'status' => 'credentials_need_reentry']);
        DB::table('automation_accounts')->where('id', $account->id)->update([
            'email_encrypted' => 'old-key-ciphertext',
            'password_encrypted' => 'old-key-ciphertext',
        ]);
        Http::fake(['*' => Http::response(['success' => true, 'data' => [
            'session_state' => ['cookies' => [['name' => 'session', 'value' => 'test']]],
        ]])]);

        $this->actingAs($admin);
        Livewire::test(AccountSessions::class)
            ->set('emails.soundon', 'target@example.com')
            ->set('passwords.soundon', 'new-password')
            ->call('saveAndLogin', 'soundon')
            ->assertSet('message', 'soundon: active')
            ->assertSee('Aktif');

        $account->refresh();
        $this->assertSame('active', $account->status);
        $this->assertSame('target@example.com', $account->email_encrypted);
        $this->assertNotNull($account->session_state_encrypted);
    }

    public function test_download_folder_resolves_to_private_automation_downloads_directory(): void
    {
        $component = new AccountSessions;
        $method = new \ReflectionMethod($component, 'downloadFolderPath');
        $path = str_replace('\\', '/', $method->invoke($component));

        $this->assertStringEndsWith('/storage/app/private/automation/downloads', $path);
    }

    public function test_admin_can_save_automatic_run_schedule(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        Livewire::actingAs($admin)
            ->test(AccountSessions::class)
            ->set('automaticRunEnabled', true)
            ->set('automaticRunIntervalMinutes', 30)
            ->set('automaticRunStartTime', '08:00')
            ->set('automaticRunEndTime', '22:00')
            ->call('saveAutomaticRunSettings')
            ->assertHasNoErrors()
            ->assertSee('Jadwal otomatis berhasil disimpan');

        $this->assertDatabaseHas('automation_settings', [
            'automatic_run_enabled' => true,
            'automatic_run_interval_minutes' => 30,
            'automatic_run_start_time' => '08:00',
            'automatic_run_end_time' => '22:00',
        ]);
    }

    public function test_admin_can_clear_downloaded_release_assets(): void
    {
        Storage::fake('local');
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        $run = AutomationRun::create(['status' => 'completed']);
        $job = ReleaseJob::create(['automation_run_id' => $run->id, 'soundfresh_release_id' => 'download-cleanup', 'soundfresh_release_url' => 'https://cms.soundfresh.id/releases/download-cleanup', 'idempotency_key' => 'download-cleanup', 'status' => 'completed']);
        $path = 'automation/downloads/Test - Artist/Test-Artist.jpg';
        Storage::disk('local')->put($path, 'cover');
        $asset = ReleaseAsset::create(['release_job_id' => $job->id, 'type' => 'cover', 'temporary_path' => $path, 'original_filename' => 'Test-Artist.jpg']);

        Livewire::test(AccountSessions::class)->call('clearDownloadData')->assertSet('downloadFiles', 0)->assertSee('Data download berhasil dihapus');

        Storage::disk('local')->assertMissing($path);
        $this->assertNull($asset->fresh()->temporary_path);
        $this->assertNotNull($asset->fresh()->deleted_at_source_cache);
    }
}
