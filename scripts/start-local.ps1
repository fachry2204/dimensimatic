param([int]$Port = 8012)

$ErrorActionPreference = 'Stop'
$project = Split-Path -Parent $PSScriptRoot
$logs = Join-Path $project 'storage\logs'
$php = 'D:\xampp\php\php.exe'
$npm = 'C:\Program Files\nodejs\npm.cmd'

# Some desktop launch environments expose both `Path` and `PATH`. Windows
# PowerShell's Start-Process treats those as duplicate dictionary keys.
$processPath = [Environment]::GetEnvironmentVariable('Path', [EnvironmentVariableTarget]::Process)
[Environment]::SetEnvironmentVariable('Path', $null, [EnvironmentVariableTarget]::Process)
[Environment]::SetEnvironmentVariable('PATH', $null, [EnvironmentVariableTarget]::Process)
if ($processPath) {
    [Environment]::SetEnvironmentVariable('Path', $processPath, [EnvironmentVariableTarget]::Process)
}

$appKeyLine = Get-Content (Join-Path $project '.env') | Where-Object { $_ -like 'APP_KEY=*' } | Select-Object -First 1
if (-not $appKeyLine) {
    throw 'APP_KEY is not configured.'
}
$appKey = $appKeyLine.Substring('APP_KEY='.Length)
$hmacMaterial = [Text.Encoding]::UTF8.GetBytes($appKey + '|dimensimatic-automation-hmac')
$sha256 = [Security.Cryptography.SHA256]::Create()
$env:AUTOMATION_HMAC_KEY = ([BitConverter]::ToString($sha256.ComputeHash($hmacMaterial))).Replace('-', '').ToLowerInvariant()
$sha256.Dispose()
$env:PLAYWRIGHT_SERVICE_URL = 'http://127.0.0.1:3112'
$env:PORT = '3112'
$chromium = Get-ChildItem (Join-Path $env:LOCALAPPDATA 'ms-playwright') -Directory -Filter 'chromium-*' -ErrorAction SilentlyContinue | Sort-Object Name -Descending | ForEach-Object { Get-ChildItem $_.FullName -Recurse -Filter 'chrome.exe' -ErrorAction SilentlyContinue | Select-Object -First 1 } | Select-Object -First 1
if ($chromium) {
    $env:PLAYWRIGHT_CHROMIUM_EXECUTABLE = $chromium.FullName
}

$app = Start-Process -FilePath $php -ArgumentList @('artisan', 'serve', '--host=127.0.0.1', "--port=$Port") -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'app-service.log') -RedirectStandardError (Join-Path $logs 'app-service-error.log') -PassThru
$releaseWorkers = @()
foreach ($slot in 1..2) {
    $releaseWorkers += Start-Process -FilePath $php -ArgumentList @('artisan', 'queue:work', 'database', '--queue=release-automation,default', '--sleep=3', '--tries=1', '--timeout=2700', '--rest=1') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs "queue-service-$slot.log") -RedirectStandardError (Join-Path $logs "queue-service-$slot-error.log") -PassThru
}
$statusQueue = Start-Process -FilePath $php -ArgumentList @('artisan', 'queue:work', 'database', '--queue=status-checks', '--sleep=1', '--tries=3', '--timeout=2700') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'status-queue-service.log') -RedirectStandardError (Join-Path $logs 'status-queue-service-error.log') -PassThru
$cmsQueue = Start-Process -FilePath $php -ArgumentList @('artisan', 'queue:work', 'database', '--queue=cms-verification', '--sleep=1', '--tries=3', '--timeout=2700') -WorkingDirectory $project -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'cms-queue-service.log') -RedirectStandardError (Join-Path $logs 'cms-queue-service-error.log') -PassThru
$worker = Start-Process -FilePath $npm -ArgumentList @('start') -WorkingDirectory (Join-Path $project 'automation-worker') -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logs 'browser-worker.log') -RedirectStandardError (Join-Path $logs 'browser-worker-error.log') -PassThru

$statusQueueId = if ($statusQueue) { $statusQueue.Id } else { $null }
[pscustomobject]@{ Laravel = $app.Id; ReleaseQueues = @($releaseWorkers | ForEach-Object Id); StatusQueue = $statusQueueId; CmsQueue = $cmsQueue.Id; BrowserWorker = $worker.Id; BrowserSlots = 2; Port = $Port } | ConvertTo-Json
