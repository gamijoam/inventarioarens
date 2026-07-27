param(
    [Parameter(Mandatory = $true)]
    [string] $AppRoot,

    [string] $OpenPath = "/"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$php = Join-Path $AppRoot "runtime\php\php.exe"
$router = Join-Path $AppRoot "installer\windows\router.php"
$frontendRoot = Join-Path $AppRoot "frontend\dist"
$dataRoot = Join-Path $env:ProgramData "InventarioArens"
$logRoot = Join-Path $dataRoot "logs"
$caBundle = Join-Path $dataRoot "certificates\cacert.pem"
$caBundleSource = Join-Path $AppRoot "installer\windows\cacert.pem"
$phpConfigRoot = Join-Path $dataRoot "php-conf"

function Quote-Argument {
    param([Parameter(Mandatory = $true)][string] $Value)

    return '"' + ($Value -replace '"', '\"') + '"'
}

New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
New-Item -ItemType Directory -Force -Path (Split-Path -Parent $caBundle) | Out-Null

if (Test-Path -LiteralPath $caBundleSource) {
    Copy-Item -LiteralPath $caBundleSource -Destination $caBundle -Force
}

# Debe existir antes de iniciar PHP: Laravel decide storage_path durante el
# bootstrap y Program Files no es escribible para un usuario normal.
$env:LARAVEL_STORAGE_PATH = $dataRoot
$env:PHP_INI_SCAN_DIR = $phpConfigRoot

if (!(Test-Path -LiteralPath $php)) { throw "No se encontro PHP portable." }
if (!(Test-Path -LiteralPath $frontendRoot)) { throw "No se encontro frontend/dist." }

Push-Location $AppRoot
try {
    $caBundleForPhp = $caBundle.Replace('\', '/')
    $apiArguments = '-d curl.cainfo=' + $caBundleForPhp + ' -d openssl.cafile=' + $caBundleForPhp + ' artisan serve --host=127.0.0.1 --port=8787'
    $frontendArguments = '-S 127.0.0.1:5173 -t ' + (Quote-Argument "frontend/dist") + ' ' + (Quote-Argument $router)

    $apiListening = Get-NetTCPConnection -LocalPort 8787 -State Listen -ErrorAction SilentlyContinue
    if (!$apiListening) {
        Start-Process -FilePath $php -ArgumentList $apiArguments -WorkingDirectory $AppRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot "api.out.log") -RedirectStandardError (Join-Path $logRoot "api.err.log")
    }

    $frontendListening = Get-NetTCPConnection -LocalPort 5173 -State Listen -ErrorAction SilentlyContinue
    if (!$frontendListening) {
        Start-Process -FilePath $php -ArgumentList $frontendArguments -WorkingDirectory $AppRoot -WindowStyle Hidden -RedirectStandardOutput (Join-Path $logRoot "frontend.out.log") -RedirectStandardError (Join-Path $logRoot "frontend.err.log")
    }
} finally {
    Pop-Location
}

$apiReady = $false
for ($attempt = 1; $attempt -le 15; $attempt++) {
    try {
        $response = Invoke-WebRequest -Uri "http://127.0.0.1:8787/api/bootstrap/status" -UseBasicParsing -TimeoutSec 2
        if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 300) {
            $apiReady = $true
            break
        }
    } catch {
        Start-Sleep -Seconds 1
    }
}

if (!$apiReady) {
    throw "La API local no quedo lista. Revisa $logRoot\\api.err.log y $logRoot\\api.out.log."
}

if (!$OpenPath.StartsWith("/")) {
    $OpenPath = "/" + $OpenPath
}

Start-Process "http://127.0.0.1:5173$OpenPath"
