<?php

use App\Http\Controllers\Auth\LoginController;
use App\Livewire\Automation\AccountSessions;
use App\Livewire\Automation\ApplicationUpdate;
use App\Livewire\Automation\Dashboard;
use App\Livewire\Automation\MetadataMappings;
use App\Livewire\Automation\NotificationCenter;
use App\Livewire\Automation\ReleaseStatus;
use App\Livewire\Automation\SoundOnReviews;
use App\Livewire\Automation\RunDetail;
use App\Livewire\Automation\SyncMetadata;
use App\Livewire\Automation\UserManagement;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::get('/login/system-status', [LoginController::class, 'systemStatus'])->middleware('throttle:30,1')->name('login.system-status');
    // Start/stop is idempotent and may be clicked again while the service is
    // booting. Allow normal recovery attempts instead of returning a 429 that
    // makes the operator think the browser worker cannot start.
    Route::post('/login/start-system', [LoginController::class, 'startSystem'])->middleware('throttle:30,1')->name('login.start-system');
    Route::post('/login/stop-system', [LoginController::class, 'stopSystem'])->middleware('throttle:30,1')->name('login.stop-system');
    // Operator dapat membuka beberapa tab lokal dan melakukan koreksi kredensial
    // saat setup. Batas 5/minute membuat halaman login mudah terkunci oleh
    // percobaan normal; tetap batasi, tetapi beri ruang yang wajar.
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->middleware('permission:automation.view')->name('automation.dashboard');
    Route::get('/runs/{run}', RunDetail::class)->middleware('permission:automation.view')->name('automation.runs.show');
    Route::get('/mappings', MetadataMappings::class)->middleware('permission:mappings.manage')->name('automation.mappings');
    Route::get('/sync-metadata', SyncMetadata::class)->middleware('permission:mappings.manage')->name('automation.sync-metadata');
    Route::get('/sessions', AccountSessions::class)->middleware('permission:sessions.manage')->name('automation.sessions');
    Route::get('/update', ApplicationUpdate::class)->middleware('permission:sessions.manage')->name('automation.update');
    Route::get('/notifications', NotificationCenter::class)->middleware('permission:automation.view')->name('automation.notifications');
    Route::get('/release-status', ReleaseStatus::class)->middleware('permission:release-status.view')->name('automation.release-status');
    Route::get('/reviews', SoundOnReviews::class)->middleware('permission:release-status.view')->name('automation.reviews');
    Route::get('/users', UserManagement::class)->middleware('permission:users.manage')->name('automation.users');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
});
