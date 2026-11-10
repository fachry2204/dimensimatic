<?php

declare(strict_types=1);

// Detached Windows queue worker launcher. The PID is stored before Artisan
// starts so the dashboard can reliably detect the long-running PHP process.
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

$arguments = array_slice($argv, 2);
if ($arguments === []) {
    fwrite(STDERR, "Missing Artisan command.\n");
    exit(1);
}

$_SERVER['argv'] = array_merge([dirname(__DIR__).DIRECTORY_SEPARATOR.'artisan'], $arguments);
$_SERVER['argc'] = count($_SERVER['argv']);

require dirname(__DIR__).DIRECTORY_SEPARATOR.'artisan';
