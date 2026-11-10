<?php

namespace Tests\Feature;

use App\Livewire\Automation\AccountSessions;
use App\Models\AutomationSetting;
use App\Models\User;
use App\Support\SystemBranding;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

class SystemIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(AccessControlSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        return $admin;
    }

    public function test_saved_name_and_png_appear_on_settings_navigation_and_guest_login(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Livewire::actingAs($this->admin())->test(AccountSessions::class)
            ->set('systemName', 'Atlas & Musik')
            ->set('faviconUpload', UploadedFile::fake()->createWithContent('brand.png', $png))
            ->call('saveSystemIdentity')->assertHasNoErrors()
            ->assertDispatched('system-branding-updated')
            ->assertSee('Atlas &amp; Musik', false)->assertDontSee('Link login CMS Dimensi Suara');

        $this->assertSame('Atlas & Musik', AutomationSetting::current()->system_name);
        $uri = 'data:image/png;base64,'.base64_encode($png);
        $this->assertSame($uri, AutomationSetting::current()->favicon_data_uri);
        $this->get('/sessions')->assertOk()->assertSee('data-system-name="Atlas &amp; Musik"', false);
        auth()->logout();
        $this->get('/login')->assertOk()
            ->assertSee('Login — Atlas &amp; Musik Automation', false)
            ->assertSee('href="'.$uri.'"', false);
    }

    public function test_ico_upload_is_persisted_and_can_be_reset_without_changing_name(): void
    {
        $ico = file_get_contents(public_path('favicon.ico'));
        Livewire::actingAs($this->admin())->test(AccountSessions::class)
            ->set('systemName', 'Musik Studio')
            ->set('faviconUpload', UploadedFile::fake()->createWithContent('brand.ico', $ico))
            ->call('saveSystemIdentity')->assertHasNoErrors();
        $this->assertSame('data:image/x-icon;base64,'.base64_encode($ico), AutomationSetting::current()->favicon_data_uri);
        Livewire::test(AccountSessions::class)->set('systemName', 'Musik Baru')
            ->call('saveSystemIdentity')->assertHasNoErrors();
        $this->assertNotNull(AutomationSetting::current()->favicon_data_uri);
        Livewire::test(AccountSessions::class)->set('removeFavicon', true)
            ->call('saveSystemIdentity')->assertHasNoErrors();
        $this->assertNull(AutomationSetting::current()->favicon_data_uri);
        $this->assertSame('Musik Baru', AutomationSetting::current()->system_name);
    }

    public function test_invalid_name_or_non_image_upload_does_not_replace_saved_identity(): void
    {
        $admin = $this->admin();
        AutomationSetting::current()->update(['system_name' => 'Saved Studio']);
        SystemBranding::refresh();
        foreach (['', str_repeat('a', 81), '<script>alert(1)</script>', "bad\nname"] as $name) {
            Livewire::actingAs($admin)->test(AccountSessions::class)->set('systemName', $name)
                ->call('saveSystemIdentity')->assertHasErrors('systemName');
        }
        Livewire::test(AccountSessions::class)->set('systemName', 'New Studio')
            ->set('faviconUpload', UploadedFile::fake()->createWithContent('brand.png', '<svg onload="alert(1)"></svg>'))
            ->call('saveSystemIdentity')->assertHasErrors('faviconUpload');
        $this->assertSame('Saved Studio', AutomationSetting::current()->system_name);
    }

    public function test_user_without_permission_cannot_change_identity(): void
    {
        Livewire::actingAs(User::factory()->create())->test(AccountSessions::class)
            ->set('systemName', 'Other')->call('saveSystemIdentity')->assertForbidden();
        $this->assertNull(AutomationSetting::current()->system_name);
    }

    public function test_old_display_names_follow_saved_name_without_changing_internal_identifiers(): void
    {
        AutomationSetting::current()->update(['system_name' => 'Soundfresh Studio']);
        SystemBranding::refresh();
        $text = SystemBranding::text('Soundfresh / Dimensi Suara / Dimensimatic / soundfresh_reviewed');
        $this->assertSame('Soundfresh Studio / Soundfresh Studio / Soundfresh Studio / soundfresh_reviewed', $text);
        $this->assertSame($text, SystemBranding::text($text));
    }
}
