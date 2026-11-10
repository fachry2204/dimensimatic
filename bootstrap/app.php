<?php

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;

// Windows cannot reliably use the PowerShell host on this machine.  When the
// local dashboard starts a queue worker it invokes this existing bootstrap as
// a detached CLI supervisor that owns the worker PID for its full lifetime.
if (PHP_SAPI === 'cli' && basename((string) ($argv[0] ?? '')) === 'app.php' && ($argv[1] ?? null) === '--queue-runner') {
    $pidFile = $argv[2] ?? '';
    $arguments = array_slice($argv, 3);
    if ($pidFile === '' || $arguments === []) {
        fwrite(STDERR, "Missing queue worker arguments.\n");
        exit(1);
    }

    // Retained for the supervisor lifetime: concurrent launchers must not
    // create a second consumer or replace the live owner's PID.
    $workerLock = fopen($pidFile.'.lock', 'c');
    if ($workerLock === false) {
        fwrite(STDERR, "Cannot open worker ownership lock.\n");
        exit(1);
    }
    if (! flock($workerLock, LOCK_EX | LOCK_NB)) exit(0);
    file_put_contents($pidFile, (string) getmypid(), LOCK_EX);
    register_shutdown_function(static function () use ($pidFile): void {
        if (is_file($pidFile) && trim((string) file_get_contents($pidFile)) === (string) getmypid()) {
            @unlink($pidFile);
        }
    });

    // `artisan` loads this bootstrap with require_once; invoking it in-process
    // would therefore return boolean true instead of the Laravel application.
    // Run it as the child process and keep this launcher alive as its durable
    // supervisor/PID owner.
    $command = implode(' ', array_map(
        'escapeshellarg',
        array_merge([PHP_BINARY, dirname(__DIR__).DIRECTORY_SEPARATOR.'artisan'], $arguments),
    ));
    passthru($command, $status);
    exit($status);
}

// Keep a small PHP supervisor around the Node browser worker as well.  The
// supervisor owns a stable PID and lets taskkill /T stop Chromium descendants
// before a clean worker is started again.
if (PHP_SAPI === 'cli' && basename((string) ($argv[0] ?? '')) === 'app.php' && ($argv[1] ?? null) === '--node-runner') {
    $pidFile = $argv[2] ?? '';
    $directory = $argv[3] ?? '';
    $node = $argv[4] ?? '';
    if ($pidFile === '' || $directory === '' || $node === '' || ! is_dir($directory)) {
        fwrite(STDERR, "Missing browser worker arguments.\n");
        exit(1);
    }

    file_put_contents($pidFile, (string) getmypid(), LOCK_EX);
    register_shutdown_function(static function () use ($pidFile): void {
        if (is_file($pidFile) && trim((string) file_get_contents($pidFile)) === (string) getmypid()) {
            @unlink($pidFile);
        }
    });

    chdir($directory);
    $command = implode(' ', array_map('escapeshellarg', [
        $node,
        '--env-file=.env',
        $directory.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'server.js',
    ]));
    passthru($command, $status);
    exit($status);
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [SecurityHeaders::class]);
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Laravel's default behaviour converts TokenMismatchException to
        // HttpException(419) and displays a generic error page.  In the
        // desktop WebView2 context the session file may not exist yet when
        // the very first login POST arrives (just after install / update),
        // producing the raw "419 | PAGE EXPIRED" screen.  Redirect back to
        // /login with a friendly message instead so the user can simply
        // retry without any confusing error page.
        $exceptions->render(function (
            \Symfony\Component\HttpKernel\Exception\HttpException $exception,
            \Illuminate\Http\Request $request,
        ): ?\Illuminate\Http\RedirectResponse {
            if ($exception->getStatusCode() !== 419
                || ! $request->isMethod('POST')
                || ! $request->is('login')
                || $request->expectsJson()) {
                return null;
            }

            return redirect()->route('login')
                ->withErrors(['username' => 'Sesi login telah kedaluwarsa atau tidak tersedia. Silakan coba masuk kembali.'])
                ->withInput($request->except(['password', '_token']));
        });
    })->create();
