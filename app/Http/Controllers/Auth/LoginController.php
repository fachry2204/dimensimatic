<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Automation\LocalSystemManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class LoginController extends Controller
{
    public function create(LocalSystemManager $system): View
    {
        return view('auth.login', ['systemStatus' => $system->status()]);
    }

    public function systemStatus(Request $request, LocalSystemManager $system): JsonResponse
    {
        $this->ensureLocalRequest($request);

        return response()->json($system->status());
    }

    public function startSystem(Request $request, LocalSystemManager $system): RedirectResponse
    {
        $this->ensureLocalRequest($request);

        try {
            return back()->with('system_message', $system->start()['message']);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['system' => $exception->getMessage()]);
        }
    }

    public function stopSystem(Request $request, LocalSystemManager $system): RedirectResponse
    {
        $this->ensureLocalRequest($request);

        try {
            return back()->with('system_message', $system->stopAllWorkers()['message']);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['system' => $exception->getMessage()]);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['username' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $credentials['is_active'] = true;
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['username' => 'Username atau password tidak valid.'])->onlyInput('username');
        }

        $request->session()->regenerate();
        User::whereKey(Auth::id())->update(['last_login_at' => now()]);

        $defaultRoute = Auth::user()?->can('automation.view')
            ? route('automation.dashboard')
            : route('automation.release-status');

        return redirect()->intended($defaultRoute);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function ensureLocalRequest(Request $request): void
    {
        abort_unless(in_array($request->ip(), ['127.0.0.1', '::1'], true), 403);
    }
}
