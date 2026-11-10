<?php

declare(strict_types=1);

namespace App\Services\Automation;

use RuntimeException;
use Symfony\Component\Process\Process;

final class LocalSystemManager
{
    /** @return array{server: bool, worker: bool, release_workers: int, status_workers: int, cms_workers: int, queues_online: bool, worker_slots: int, browser_slots: int} */
    public function status(): array
    {
        $releaseWorkers = $this->queueWorkerCount('release-automation');
        $statusWorkers = $this->queueWorkerCount('status-checks');
        $cmsWorkers = $this->queueWorkerCount('cms-verification');

        return [
            'server' => $this->serverIsOnline(),
            'worker' => $this->workerIsOnline(),
            'release_workers' => $releaseWorkers,
            'status_workers' => $statusWorkers,
            'cms_workers' => $cmsWorkers,
            'queues_online' => $releaseWorkers > 0 && $statusWorkers > 0 && $cmsWorkers > 0,
            'worker_slots' => (int) config('automation.release_queue_workers', 2),
            'browser_slots' => (int) config('automation.browser_concurrency', 2),
        ];
    }

    /** @return array{server: bool, worker: bool, message: string} */
    public function start(): array
    {
        if (app()->environment('testing')) {
            return [...$this->status(), 'message' => 'Worker tidak dinyalakan pada lingkungan pengujian.'];
        }

        if (! $this->serverIsOnline()) {
            $this->startServer();
        }
        if (! $this->workerIsOnline()) {
            $this->startWorker();
        }
        $this->startQueueWorkers();

        $deadline = microtime(true) + 10;
        do {
            usleep(200_000);
            $status = $this->status();
            if ($status['server'] && $status['worker'] && $status['queues_online']) {
                return [...$status, 'message' => 'Server, browser worker, dan queue worker berhasil dinyalakan.'];
            }
        } while (microtime(true) < $deadline);

        throw new RuntimeException('Server, browser worker, atau queue worker belum merespons. Periksa storage/logs/app-service-error.log, automation-worker-error.log, dan release-worker.log.');
    }

    /** Stop only the local browser worker process; the web server stays online. */
    public function stop(): array
    {
        if (app()->environment('testing')) {
            return [...$this->status(), 'message' => 'Worker tidak dihentikan pada lingkungan pengujian.'];
        }

        $stopped = false;
        $pidFile = storage_path('app/automation-worker.pid');
        if (is_file($pidFile)) {
            $pid = (int) trim((string) file_get_contents($pidFile));
            if ($pid > 0 && PHP_OS_FAMILY === 'Windows') {
                $commandLine = $this->windowsProcessCommandLine($pid);
                if ($this->ownsWindowsProcess($commandLine)) {
                    $kill = new Process(['taskkill.exe', '/PID', (string) $pid, '/T', '/F']);
                    $kill->setTimeout(5)->run();
                    $stopped = true;
                }
            }
            @unlink($pidFile);
        }

        // A worker can outlive its PID file after a desktop restart. Stop any
        // remaining local SoundOn worker as well, without loading PowerShell
        // or touching unrelated Node services on the computer.
        if (PHP_OS_FAMILY === 'Windows') {
            foreach ($this->windowsBrowserWorkerProcessIds() as $processId) {
                $kill = new Process(['taskkill.exe', '/PID', (string) $processId, '/T', '/F']);
                $kill->setTimeout(5)->run();
                $stopped = true;
            }
        }

        return [...$this->status(), 'message' => $stopped ? 'Worker berhasil dihentikan.' : 'Worker sudah berhenti.'];
    }

    /** Start the durable Laravel queue workers used by collection, upload, verification, and checks. */
    public function startQueueWorkers(): void
    {
        if (app()->environment('testing') || PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        if ($this->queueWorkerCount('release-automation') === 0) {
            $this->startQueueWorker('release-automation,default', 'release-worker.log', 'release-worker-error.log');
        }
        if ($this->queueWorkerCount('cms-verification') === 0) {
            $this->startQueueWorker('cms-verification', 'cms-worker.log', 'cms-worker-error.log');
        }
        if ($this->queueWorkerCount('status-checks') === 0) {
            $this->startQueueWorker('status-checks', 'status-worker.log', 'status-worker-error.log');
        }
    }

    /** Stop every local queue process for the requested queues, including an older process without a PID file. */
    public function stopQueueWorkers(?array $queues = null): void
    {
        if (app()->environment('testing') || PHP_OS_FAMILY !== 'Windows') {
            return;
        }

        foreach ($queues ?? ['release-automation', 'status-checks', 'cms-verification'] as $queue) {
            $pidFile = $this->queueWorkerPidFile((string) $queue);
            if (is_file($pidFile)) {
                $pid = (int) trim((string) file_get_contents($pidFile));
                if ($pid > 0 && $this->ownsWindowsProcess($this->windowsProcessCommandLine($pid))) {
                    // The recorded PID supervises a child Artisan process.
                    // Stop its tree so the old child cannot retain the queue.
                    $kill = new Process(['taskkill.exe', '/PID', (string) $pid, '/T', '/F']);
                    $kill->setTimeout(5)->run();
                }
                @unlink($pidFile);
            }
            // A detached Artisan child can become orphaned from its recorded
            // supervisor PID. Terminate every exact queue process discovered
            // through WMIC so Stop followed by Ambil starts a clean consumer.
            foreach ($this->windowsQueueProcessIds((string) $queue) as $processId) {
                $kill = new Process(['taskkill.exe', '/PID', (string) $processId, '/T', '/F']);
                $kill->setTimeout(5)->run();
            }

        }
    }

    /** Stop the browser worker and both Laravel queue workers without stopping the web server. */
    public function stopAllWorkers(): array
    {
        $this->stopQueueWorkers();
        $browser = $this->stop();

        return [...$this->status(), 'message' => 'Browser worker dan seluruh queue worker berhasil dihentikan. '.$browser['message']];
    }

    private function workerIsOnline(): bool
    {
        $url = parse_url((string) config('automation.worker_url'));
        $host = (string) ($url['host'] ?? '127.0.0.1');
        $port = (int) ($url['port'] ?? 3112);
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.35);
        if (! is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function serverIsOnline(): bool
    {
        $url = parse_url((string) config('automation.local_server_url'));
        $host = (string) ($url['host'] ?? '127.0.0.1');
        $port = (int) ($url['port'] ?? (($url['scheme'] ?? 'http') === 'https' ? 443 : 80));
        $socket = @fsockopen($host, $port, $errorCode, $errorMessage, 0.35);
        if (! is_resource($socket)) {
            return false;
        }
        fclose($socket);

        return true;
    }

    private function queueWorkerCount(string $queue): int
    {
        if (app()->environment('testing') || PHP_OS_FAMILY !== 'Windows') {
            return 0;
        }

        $pidFile = $this->queueWorkerPidFile($queue);
        if (! is_file($pidFile)) {
            return $this->windowsQueueProcessExists($queue) ? 1 : 0;
        }
        $pid = (int) trim((string) file_get_contents($pidFile));
        if ($pid < 1) {
            @unlink($pidFile);

            return 0;
        }

        $alive = $this->ownsWindowsProcess($this->windowsProcessCommandLine($pid));
        if (! $alive) {
            @unlink($pidFile);
        }

        return $alive ? 1 : 0;
    }

    private function windowsQueueProcessExists(string $queue): bool
    {
        // The detached supervisor and Artisan child can stay healthy even if
        // an older supervisor removes a freshly replaced PID file. WMIC is
        // available on the supported Windows host and does not load the
        // managed PowerShell runtime that is broken on this machine.
        return $this->windowsQueueProcessIds($queue) !== [];
    }

    private function windowsProcessCommandLine(int $processId): string
    {
        return (string) shell_exec(
            'wmic.exe process where "ProcessId='.$processId.'" get CommandLine,ExecutablePath /value 2>NUL'
        );
    }

    private function ownsWindowsProcess(string $record): bool
    {
        $record = strtolower(str_replace('\\', '/', $record));
        foreach (['runtime/php/php.exe', 'runtime/node/node.exe', 'bootstrap/app.php', 'artisan', 'automation-worker/dist/server.js'] as $relativePath) {
            $path = strtolower(str_replace('\\', '/', base_path($relativePath)));
            if (preg_match('/(?:^|[\s"=])'.preg_quote($path, '/').'(?:$|[\s"])/', $record) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{pid: int, record: string}> */
    private function windowsOwnedProcesses(string $name): array
    {
        $output = (string) shell_exec(
            'wmic.exe process where "name=\''.$name.'\'" get CommandLine,ExecutablePath,ProcessId /format:list 2>NUL'
        );
        $processes = [];
        foreach (preg_split('/\n{2,}/', trim(str_replace("\r", '', $output))) ?: [] as $record) {
            if ($this->ownsWindowsProcess($record) && preg_match('/ProcessId=(\d+)/', $record, $match)) {
                $processes[] = ['pid' => (int) $match[1], 'record' => $record];
            }
        }

        return $processes;
    }

    /** @return list<int> */
    private function windowsBrowserWorkerProcessIds(): array
    {
        return array_column(array_filter($this->windowsOwnedProcesses('node.exe'),
            fn (array $process): bool => str_contains($process['record'], 'server.js')), 'pid');
    }

    /** @return list<int> */
    private function windowsQueueProcessIds(string $queue): array
    {
        return array_column(array_filter($this->windowsOwnedProcesses('php.exe'),
            fn (array $process): bool => str_contains($process['record'], 'queue:work')
                && preg_match('/--queue='.preg_quote($queue, '/').'(?:,|[\s"]|$)/', $process['record']) === 1), 'pid');
    }

    private function startQueueWorker(string $queue, string $outputLog, string $errorLog): void
    {
        $pidFile = $this->queueWorkerPidFile(explode(',', $queue)[0]);
        $this->startWindowsArtisanProcess(
            ['queue:work', 'database', '--queue='.$queue, '--sleep=2', '--tries=3', '--timeout=2700'],
            $pidFile,
            storage_path('logs/'.$outputLog),
            storage_path('logs/'.$errorLog),
        );
    }

    private function queueWorkerPidFile(string $queue): string
    {
        return storage_path('app/'.match ($queue) { 'release-automation' => 'release-queue-worker.pid', 'cms-verification' => 'cms-queue-worker.pid', default => 'status-queue-worker.pid' });
    }

    private function startWorker(): void
    {
        $directory = base_path('automation-worker');
        $entrypoint = $directory.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'server.js';
        if (! is_file($entrypoint)) {
            throw new RuntimeException('Build worker tidak ditemukan. Jalankan npm run build terlebih dahulu.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $node = (string) config('automation.node_binary', 'C:\\Program Files\\nodejs\\node.exe');
            $log = storage_path('logs/automation-worker.log');
            $pidFile = storage_path('app/automation-worker.pid');
            $this->startWindowsNodeProcess(
                $node,
                $directory,
                $pidFile,
                $log,
                storage_path('logs/automation-worker-error.log'),
            );

            return;
        }

        $process = new Process(['node', '--env-file=.env', 'dist/server.js'], $directory);
        $process->disableOutput();
        $process->setTimeout(null);
        $process->start();
    }

    private function startServer(): void
    {
        $url = parse_url((string) config('automation.local_server_url'));
        $host = (string) ($url['host'] ?? '127.0.0.1');
        $port = (int) ($url['port'] ?? 8012);
        $php = PHP_BINARY;
        $pidFile = storage_path('app/laravel-server.pid');
        $log = storage_path('logs/app-service.log');
        $errorLog = storage_path('logs/app-service-error.log');

        if (PHP_OS_FAMILY === 'Windows') {
            $this->startWindowsArtisanProcess(
                ['serve', '--host='.$host, '--port='.$port],
                $pidFile,
                $log,
                $errorLog,
            );

            return;
        }

        $process = new Process([$php, 'artisan', 'serve', '--host='.$host, '--port='.$port], base_path());
        $process->disableOutput();
        $process->setTimeout(null);
        $process->start();
    }

    /** @param array<int, string> $arguments */
    private function startWindowsArtisanProcess(array $arguments, string $pidFile, string $outputLog, string $errorLog): void
    {
        [$outputLog, $errorLog] = $this->uniqueWorkerLogs($pidFile, $outputLog, $errorLog);
        $wrapper = base_path('bootstrap/app.php');
        // An inline `cmd /C start ...` command loses its quoting when a
        // Windows account/profile path contains a space. Place the exact
        // quoted command in a local launcher file instead; cmd receives the
        // launcher as one argument and can reliably start the detached worker.
        $launcher = $this->writeWindowsLauncher(
            basename($pidFile, '.pid').'.cmd',
            'start "" /B '.$this->quoteWindowsArgument(PHP_BINARY)
                .' '.$this->quoteWindowsArgument($wrapper)
                .' --queue-runner '.$this->quoteWindowsArgument($pidFile)
                .' '.implode(' ', $arguments)
                .' > '.$this->quoteWindowsArgument($outputLog)
                .' 2> '.$this->quoteWindowsArgument($errorLog),
        );
        $process = new Process(['cmd.exe', '/D', '/S', '/C', $launcher], base_path());
        $process->setTimeout(15)->mustRun();
    }

    private function startWindowsNodeProcess(string $node, string $directory, string $pidFile, string $outputLog, string $errorLog): void
    {
        [$outputLog, $errorLog] = $this->uniqueWorkerLogs($pidFile, $outputLog, $errorLog);
        $launcher = $this->writeWindowsLauncher(
            'browser-worker.cmd',
            'start "" /B '.$this->quoteWindowsArgument(PHP_BINARY)
                .' '.$this->quoteWindowsArgument(base_path('bootstrap/app.php'))
                .' --node-runner '.$this->quoteWindowsArgument($pidFile)
                .' '.$this->quoteWindowsArgument($directory)
                .' '.$this->quoteWindowsArgument($node)
                .' > '.$this->quoteWindowsArgument($outputLog)
                .' 2> '.$this->quoteWindowsArgument($errorLog),
        );

        $process = new Process(['cmd.exe', '/D', '/S', '/C', $launcher], base_path());
        $process->setTimeout(15)->mustRun();
    }

    private function writeWindowsLauncher(string $name, string $command): string
    {
        $directory = storage_path('app');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        // cmd can retain the batch file while its detached child is alive.
        // Never overwrite a previous launcher's file during a restart.
        $launcher = $directory.DIRECTORY_SEPARATOR.pathinfo($name, PATHINFO_FILENAME).'-'.bin2hex(random_bytes(8)).'.cmd';
        file_put_contents($launcher, "@echo off\r\n".$command."\r\n", LOCK_EX);

        return $launcher;
    }

    private function uniqueWorkerLogs(string $pidFile, string $outputLog, string $errorLog): array
    {
        // Windows redirection opens files before START executes. A surviving
        // child holding the old log therefore prevents the new command from
        // starting at all. Use separate files for every attempt, including
        // attempts made by the desktop host.
        $suffix = '-'.date('Ymd-His').'-'.bin2hex(random_bytes(8)).'.log';
        $logs = [preg_replace('/\\.log$/', '', $outputLog).$suffix, preg_replace('/\\.log$/', '', $errorLog).$suffix];
        file_put_contents($pidFile.'.logs.json', json_encode(['stdout' => $logs[0], 'stderr' => $logs[1]], JSON_THROW_ON_ERROR), LOCK_EX);
        return $logs;
    }

    private function quoteWindowsArgument(string $value): string
    {
        return '"'.str_replace('"', '""', $value).'"';
    }
}
