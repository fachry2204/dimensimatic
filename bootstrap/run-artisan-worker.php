<?php

declare(strict_types=1);

// Small detached-process wrapper used by LocalSystemManager on Windows.
// It records the real worker PID before handing off to Artisan, allowing the
// dashboard to start, stop, and accurately report durable queue workers.
$pidFile = $argv[1] ?? '';
if ($pidFile === '') {
    fwrite(STDERR, "Missing PID file path.\n");
    exit(1);
}

file_put_contents($pidFile, (string) getmypid(), LOCK_EX);
register_shutdown_function(static function () use ($pidFile): void {
    if (is_file($pidFile) && trim((string) file_get_contents($pidFile)) === (string) getmypid()) {
        @unlink($pidFile);
    }
});

$artisanArguments = array_slice($argv, 2);
if ($artisanArguments === []) {
    fwrite(STDERR, "Missing Artisan command.\n");
    exit(1);
}

$_SERVER['argv'] = array_merge([dirname(__DIR__).DIRECTORY_SEPARATOR.'artisan'], $artisanArguments);
$_SERVER['argc'] = count($_SERVER['argv']);

require dirname(__DIR__).DIRECTORY_SEPARATOR.'artisan';
