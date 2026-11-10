$ErrorActionPreference = 'Stop'

$project = Split-Path -Parent $PSScriptRoot
$workerDirectory = Join-Path $project 'automation-worker'
$logs = Join-Path $project 'storage\logs'
$node = 'C:\Program Files\nodejs\node.exe'

Get-CimInstance Win32_Process |
    Where-Object { $_.Name -eq 'node.exe' -and $_.CommandLine -match 'dist/server\.js' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }

$process = Start-Process `
    -FilePath $node `
    -ArgumentList @('--env-file=.env', 'dist/server.js') `
    -WorkingDirectory $workerDirectory `
    -WindowStyle Hidden `
    -RedirectStandardOutput (Join-Path $logs 'browser-worker.log') `
    -RedirectStandardError (Join-Path $logs 'browser-worker-error.log') `
    -PassThru

Set-Content -LiteralPath (Join-Path $logs 'browser-worker.pid') -Value $process.Id
Write-Output $process.Id
