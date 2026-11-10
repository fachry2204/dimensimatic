$ErrorActionPreference = 'Stop'

$project = Split-Path -Parent $PSScriptRoot
$logs = Join-Path $project 'storage\logs'
$php = 'D:\xampp\php\php.exe'

Get-CimInstance Win32_Process |
    Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match 'artisan queue:work' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

$releaseQueues = @()
foreach ($slot in 1..2) {
    $releaseQueues += Start-Process -FilePath $php `
        -ArgumentList @('artisan', 'queue:work', 'database', '--queue=release-automation,default', '--sleep=3', '--tries=1', '--timeout=2700', '--rest=1') `
        -WorkingDirectory $project -WindowStyle Hidden `
        -RedirectStandardOutput (Join-Path $logs "queue-service-$slot.log") `
        -RedirectStandardError (Join-Path $logs "queue-service-$slot-error.log") -PassThru
}

$statusQueue = Start-Process -FilePath $php `
    -ArgumentList @('artisan', 'queue:work', 'database', '--queue=status-checks', '--sleep=1', '--tries=3', '--timeout=2700') `
    -WorkingDirectory $project -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logs 'status-queue-service.log') `
    -RedirectStandardError (Join-Path $logs 'status-queue-service-error.log') -PassThru

Write-Output "release=$($releaseQueues.Id -join ',');status=$($statusQueue.Id)"
