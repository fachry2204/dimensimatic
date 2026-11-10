<?php

declare(strict_types=1);

namespace App\Livewire\Automation;

use Illuminate\Support\Facades\Gate;
use Livewire\Component;

final class NotificationCenter extends Component
{
    public function markRead(string $id): void
    {
        Gate::authorize('automation.view');
        $notification = auth()->user()->notifications()->whereKey($id)->firstOrFail();
        $notification->markAsRead();
    }

    public function markAllRead(): void
    {
        Gate::authorize('automation.view');
        auth()->user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        return view('livewire.automation.notification-center', ['notifications' => auth()->user()->notifications()->latest()->limit(100)->get()]);
    }
}
