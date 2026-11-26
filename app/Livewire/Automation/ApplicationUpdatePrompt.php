<?php

namespace App\Livewire\Automation;

use App\Services\Automation\UpdateReleaseFeed;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

final class ApplicationUpdatePrompt extends ApplicationUpdate
{
    public bool $showModal = false;

    public function refreshNotification(): void
    {
        Gate::authorize('sessions.manage');
        if ($this->installStarted || $this->downloadReady) return;
        $snapshot = app(UpdateReleaseFeed::class)->snapshot();
        if (!isset($snapshot['release'])) return;
        $this->applyRelease($snapshot['release']);
        if ($this->updateAvailable && session('update-dismissed-version') !== $this->latestVersion) $this->showModal = true;
    }

    #[On('show-update-confirmation')]
    public function openConfirmation(): void
    {
        Gate::authorize('sessions.manage');
        $this->checkForUpdate();
        $this->showModal = true;
    }

    public function dismiss(): void
    {
        if ($this->installStarted) return;
        session(['update-dismissed-version' => $this->latestVersion]);
        $this->showModal = false;
    }

    public function render()
    {
        return view('livewire.automation.application-update-prompt');
    }
}
