param([string]$InstallPath = (Join-Path $env:LOCALAPPDATA 'Programs\Dimensimatic'))

# Read-only application diagnostics. Only this report is written; no services,
# application configuration, sessions, credentials or database are changed.
$ErrorActionPreference = 'Continue'
$report = [System.Collections.Generic.List[string]]::new()
$report.Add('Dimensimatic worker diagnostics ' + (Get-Date -Format o))
$report.Add('Install path: ' + $InstallPath)
$report.Add('Windows: ' + [Environment]::OSVersion.VersionString)
$exe = Join-Path $InstallPath 'Dimensimatic.exe'
if (Test-Path -LiteralPath $exe) { $report.Add('Version: ' + (Get-Item -LiteralPath $exe).VersionInfo.FileVersion) }

# Strict allowlist: never include APP_KEY, passwords, session or HMAC values.
$envPath = Join-Path $InstallPath '.env'
if (Test-Path -LiteralPath $envPath) {
    foreach ($line in Get-Content -LiteralPath $envPath) {
        if ($line -match '^\s*(APP_URL|SESSION_DRIVER|QUEUE_CONNECTION)\s*=') { $report.Add($line) }
    }
}
foreach ($port in @(8012, 3112)) {
    $client = [Net.Sockets.TcpClient]::new()
    try {
        $attempt = $client.ConnectAsync('127.0.0.1', $port)
        $connected = $attempt.Wait(1500) -and $client.Connected
        $report.Add("Port ${port}: $connected")
    } catch { $report.Add("Port ${port}: unavailable") }
    finally { $client.Dispose() }
}

foreach ($name in @('release-queue-worker', 'status-queue-worker', 'cms-queue-worker', 'automation-worker')) {
    $report.Add('--- ' + $name + ' ---')
    $pidPath = Join-Path $InstallPath ('storage\app\' + $name + '.pid')
    if (Test-Path -LiteralPath $pidPath) {
        $workerId = 0
        $valid = [int]::TryParse((Get-Content -LiteralPath $pidPath -Raw).Trim(), [ref]$workerId)
        $report.Add("PID valid: $valid; PID: $workerId")
        if ($valid -and $workerId -gt 0) {
            try {
                $p = Get-Process -Id $workerId -ErrorAction Stop
                $report.Add('Native process: ' + $p.ProcessName + '; started: ' + $p.StartTime)
            } catch { $report.Add('Native process lookup: ' + $_.Exception.Message) }
            $tasklist = Join-Path $env:SystemRoot 'System32\tasklist.exe'
            $report.Add('TASKLIST: ' + ((& $tasklist /FI "PID eq $workerId" /FO CSV /NH 2>&1) -join "`n"))
        }
    } else { $report.Add('PID file missing') }
    $manifest = $pidPath + '.logs.json'
    if (Test-Path -LiteralPath $manifest) {
        $report.Add('Log manifest: ' + (Get-Content -LiteralPath $manifest -Raw))
    }
    $lockPath = $pidPath + '.lock'
    if (Test-Path -LiteralPath $lockPath) {
        $lockStream = $null
        try {
            $lockStream = [IO.File]::Open($lockPath, [IO.FileMode]::Open, [IO.FileAccess]::Read, [IO.FileShare]::ReadWrite)
            $lockStream.Lock(0, 1)
            $report.Add('Ownership lock: available (no lock owner detected)')
            $lockStream.Unlock(0, 1)
        } catch { $report.Add('Ownership lock check: ' + $_.Exception.Message) }
        finally { if ($null -ne $lockStream) { $lockStream.Dispose() } }
    }
}
try {
    # Do not export command lines; those can contain secrets in other programs.
    $processes = Get-CimInstance Win32_Process -Filter "Name='php.exe' OR Name='node.exe'" -ErrorAction Stop
    foreach ($p in $processes) {
        if ($p.ExecutablePath -and $p.ExecutablePath.StartsWith($InstallPath, [StringComparison]::OrdinalIgnoreCase)) {
            $report.Add("Bundled process: $($p.Name); PID=$($p.ProcessId); ParentPID=$($p.ParentProcessId)")
        }
    }
} catch { $report.Add('Process inventory: ' + $_.Exception.Message) }

$desktop = [Environment]::GetFolderPath('Desktop')
$output = Join-Path $desktop ('Dimensimatic-diagnostic-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.txt')
$report | Set-Content -LiteralPath $output -Encoding UTF8
Write-Host "Laporan tersimpan: $output"
Read-Host 'Kirim file laporan tersebut. Tekan Enter untuk menutup'
