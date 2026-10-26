param([ValidateRange(1024,65535)][int]$Port = 8092)
$ErrorActionPreference = 'Stop'
$projectRoot = (Resolve-Path -LiteralPath (Join-Path $PSScriptRoot '../..')).Path
$php = 'C:/xampp/php/php.exe'
$sessionDir = Join-Path ([IO.Path]::GetTempPath()) 'amazon-market-sprint2-sessions'
New-Item -ItemType Directory -Path $sessionDir -Force | Out-Null
# Estas variables afectan únicamente al proceso de este servidor, no al .env.
$env:DB_NAME = 'amazon_market_sprint2_test'
$env:BASE_URL = "http://127.0.0.1:$Port"
$env:SPRINT2_SESSION_DIR = $sessionDir
Set-Location -LiteralPath $projectRoot
Write-Host "Sprint 2: $env:BASE_URL | BD: $env:DB_NAME | Ctrl+C para detener"
& $php -d "session.save_path=$sessionDir" -d display_errors=0 -d "error_log=$sessionDir/php-error.log" -S "127.0.0.1:$Port" -t $projectRoot "$PSScriptRoot/server_router.php"
exit $LASTEXITCODE
