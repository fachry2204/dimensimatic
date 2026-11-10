<?php

namespace Tests\Feature;

use App\Services\Automation\LocalSystemManager;
use Tests\TestCase;

class WindowsLauncherTest extends TestCase
{
    public function test_existing_worker_ownership_prevents_a_second_consumer(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') $this->markTestSkipped('Windows launcher');
        $directory = sys_get_temp_dir().'/Dimensimatic ownership '.bin2hex(random_bytes(5));
        mkdir($directory);
        $pid = $directory.'/worker.pid';
        file_put_contents($pid, 'existing-owner');
        $lock = fopen($pid.'.lock', 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $process = new \Symfony\Component\Process\Process([
                PHP_BINARY, base_path('bootstrap/app.php'), '--queue-runner', $pid, '--version',
            ]);
            $process->setTimeout(10)->mustRun();
            $this->assertSame('', $process->getOutput());
            $this->assertSame('existing-owner', file_get_contents($pid));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_installed_configuration_uses_bundled_node_without_a_system_install(): void
    {
        $original = base_path();
        $directory = sys_get_temp_dir().'/Dimensimatic install '.bin2hex(random_bytes(5));
        mkdir($directory.'/runtime/node', 0777, true);
        touch($directory.'/runtime/node/node.exe');
        try {
            $this->app->setBasePath($directory);
            $config = require $original.'/config/automation.php';
            $this->assertSame(base_path('runtime/node/node.exe'), $config['node_binary']);
        } finally {
            $this->app->setBasePath($original);
        }
    }

    public function test_real_windows_launcher_runs_artisan_with_spaced_output_paths(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') $this->markTestSkipped('Windows launcher');
        $directory = sys_get_temp_dir().'/Dimensimatic launcher '.bin2hex(random_bytes(5));
        mkdir($directory);
        $pid = $directory.'/probe.pid';
        $output = $directory.'/output.log';
        $error = $directory.'/error.log';
        $method = new \ReflectionMethod(LocalSystemManager::class, 'startWindowsArtisanProcess');
        $method->invoke(app(LocalSystemManager::class), ['--version'], $pid, $output, $error);
        $logs = json_decode(file_get_contents($pid.'.logs.json'), true);
        [$output, $error] = [$logs['stdout'], $logs['stderr']];
        $deadline = microtime(true) + 12;
        do {
            usleep(100000);
            clearstatcache();
            $text = is_file($output) ? file_get_contents($output) : '';
        } while (! str_contains($text, 'Laravel Framework') && microtime(true) < $deadline);
        $this->assertStringContainsString('Laravel Framework', $text, is_file($error) ? file_get_contents($error) : 'No log created');
    }

    public function test_node_supervisor_can_be_restarted_with_spaced_paths(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') $this->markTestSkipped('Windows launcher');
        $node = config('automation.node_binary');
        $this->assertFileExists($node);
        $directory = sys_get_temp_dir().'/Dimensimatic browser '.bin2hex(random_bytes(5));
        mkdir($directory.'/dist', 0777, true);
        file_put_contents($directory.'/.env', "DIMENSIMATIC_PROBE=ready\n");
        file_put_contents($directory.'/dist/server.js', "console.log(process.env.DIMENSIMATIC_PROBE);\n");
        $method = new \ReflectionMethod(LocalSystemManager::class, 'startWindowsNodeProcess');
        foreach ([1, 2] as $attempt) {
            $output = $directory.'/output '.$attempt.'.log';
            $error = $directory.'/error '.$attempt.'.log';
            $method->invoke(app(LocalSystemManager::class), $node, $directory, $directory.'/probe.pid', $output, $error);
            $logs = json_decode(file_get_contents($directory.'/probe.pid.logs.json'), true);
            [$output, $error] = [$logs['stdout'], $logs['stderr']];
            $deadline = microtime(true) + 10;
            do {
                usleep(100000);
                clearstatcache();
                $text = is_file($output) ? file_get_contents($output) : '';
            } while (! str_contains($text, 'ready') && microtime(true) < $deadline);
            $this->assertStringContainsString('ready', $text, is_file($error) ? file_get_contents($error) : 'No browser log');
        }
    }

    public function test_locked_legacy_log_does_not_prevent_a_new_worker_from_starting(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') $this->markTestSkipped('Windows file sharing');
        $dir = sys_get_temp_dir().'/Dimensimatic locked '.bin2hex(random_bytes(5));
        mkdir($dir);
        $log = $dir.'/release-worker.log';
        $holder = fopen($log, 'c+');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, LOCK_EX | LOCK_NB));
        try {
            $legacy = \Symfony\Component\Process\Process::fromShellCommandline('echo probe > "'.$log.'"');
            $legacy->run();
            $this->assertFalse($legacy->isSuccessful(), 'The legacy redirection must reproduce the sharing violation.');
            $method = new \ReflectionMethod(LocalSystemManager::class, 'startWindowsArtisanProcess');
            $method->invoke(app(LocalSystemManager::class), ['--version'], $dir.'/worker.pid', $log, $dir.'/error.log');
            $logs = json_decode(file_get_contents($dir.'/worker.pid.logs.json'), true);
            $deadline = microtime(true) + 10;
            do {
                usleep(100000); clearstatcache();
                $text = is_file($logs['stdout']) ? file_get_contents($logs['stdout']) : '';
            } while (! str_contains($text, 'Laravel Framework') && microtime(true) < $deadline);
            $this->assertStringContainsString('Laravel Framework', $text);
        } finally {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }
}
