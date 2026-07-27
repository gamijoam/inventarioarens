param(
    [Parameter(Mandatory = $true)]
    [string] $AppRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$php = Join-Path $AppRoot "runtime\php\php.exe"
$router = Join-Path $AppRoot "installer\windows\router.php"
$frontendRoot = Join-Path $AppRoot "frontend\dist"
$logRoot = Join-Path $env:ProgramData "InventarioArens\logs"

New-Item -ItemType Directory -Force -Path $logRoot | Out-Null

if (!(Test-Path -LiteralPath $php)) { throw "No se encontro PHP portable." }
if (!(Test-Path -LiteralPath $frontendRoot)) { throw "No se encontro frontend/dist." }

Push-Location $AppRoot
try {
    Start-Process -FilePath $php -ArgumentList @("artisan", "serve", "--host=127.0.0.1", "--port=8787") -WorkingDirectory $AppRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot "api.out.log") -RedirectStandardError (Join-Path $logRoot "api.err.log")
    Start-Process -FilePath $php -ArgumentList @("-S", "127.0.0.1:5173", "-t", "frontend/dist", $router) -WorkingDirectory $AppRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot "frontend.out.log") -RedirectStandardError (Join-Path $logRoot "frontend.err.log")
} finally {
    Pop-Location
}

Start-Sleep -Seconds 2
Start-Process "http://127.0.0.1:5173/"
