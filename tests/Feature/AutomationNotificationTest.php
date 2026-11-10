<?php

namespace Tests\Feature;

use App\Livewire\Automation\NotificationCenter;
use App\Models\User;
use App\Services\Automation\AttentionNotifier;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_attention_notification_contains_only_safe_operational_context(): void
    {
        $this->seed(AccessControlSeeder::class);
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        app(AttentionNotifier::class)->send('manual_auth_required', 'Operator action required.', 'run-id', 'job-id', 'AUTH_OTP_REQUIRED');
        $notification = $operator->notifications()->firstOrFail();
        $this->assertSame('AUTH_OTP_REQUIRED', $notification->data['error_code']);
        $this->assertArrayNotHasKey('password', $notification->data);
        $this->assertArrayNotHasKey('session_state', $notification->data);
    }

    public function test_operator_can_mark_own_notification_read(): void
    {
        $this->seed(AccessControlSeeder::class);
        $operator = User::factory()->create();
        $operator->assignRole('Operator');
        app(AttentionNotifier::class)->send('release_failed_final', 'Release failed.', errorCode: 'UPLOAD_FAILED');
        $this->actingAs($operator);
        $notification = $operator->notifications()->firstOrFail();
        Livewire::test(NotificationCenter::class)->call('markRead', $notification->id);
        $this->assertNotNull($notification->fresh()->read_at);
    }
}
