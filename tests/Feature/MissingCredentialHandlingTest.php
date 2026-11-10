<?php

namespace Tests\Feature;

use App\Livewire\Automation\Dashboard;
use App\Livewire\Automation\ReleaseStatus;
use App\Models\AutomationAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MissingCredentialHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_platform_credentials_are_shown_without_server_error(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        Livewire::test(Dashboard::class)->call('start')->assertOk()->assertSet('configurationError', 'Credential Dimensi Suara belum dikonfigurasi pada halaman Pengaturan.')->assertSee('Konfigurasi belum lengkap.');
    }

    public function test_unreadable_restored_credentials_block_actions_before_clearing_or_queuing(): void
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        $account = AutomationAccount::create(['platform' => 'soundfresh', 'name' => 'Dimensimatic', 'status' => 'active']);
        DB::table('automation_accounts')->where('id', $account->id)->update([
            'email_encrypted' => 'old-key-ciphertext',
            'password_encrypted' => 'old-key-ciphertext',
        ]);
        Queue::fake();

        Livewire::test(Dashboard::class)->call('start')
            ->assertSee('database lama tidak dapat dibaca');
        Livewire::test(ReleaseStatus::class)->call('refreshSoundOnStatuses')
            ->assertSee('database lama tidak dapat dibaca');
        Queue::assertNothingPushed();
        $this->assertSame('credentials_need_reentry', $account->fresh()->status);
        $this->assertSame('old-key-ciphertext', DB::table('automation_accounts')->where('id', $account->id)->value('email_encrypted'));
    }
}
