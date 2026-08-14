param(
    [string]$SourceBackendRoot,
    [string]$SourcePhpRoot,
    [string]$DataRoot = (Join-Path $env:APPDATA 'InventarioArens'),
    [string]$ServiceRoot = (Join-Path $env:ProgramData 'InventarioArens\service'),
    [switch]$Uninstall,
    [switch]$SkipStart,
    [switch]$ValidateOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$BackendService = 'InventarioArensBackend'
$PrinterService = 'InventarioArensPrinter'
$Sc = Join-Path $env:SystemRoot 'System32\sc.exe'
$PhpExeName = 'php.exe'

function Write-Info([string]$Message) {
    Write-Host "[InventarioArens] $Message"
}

function Assert-Windows {
    if ($env:OS -ne 'Windows_NT') {
        throw 'Este instalador solo se puede ejecutar en Windows.'
    }
}

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object Security.Principal.WindowsPrincipal($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Se requieren permisos de administrador para crear los servicios de Windows.'
    }
}

function Assert-File([string]$Path, [string]$Description) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "No se encontro $Description`: $Path"
    }
}

function Assert-Directory([string]$Path, [string]$Description) {
    if (-not (Test-Path -LiteralPath $Path -PathType Container)) {
        throw "No se encontro $Description`: $Path"
    }
}

function Invoke-Sc([string[]]$Arguments) {
    if (-not (Test-Path -LiteralPath $Sc -PathType Leaf)) {
        throw "No se encontro sc.exe: $Sc"
    }

    & $Sc @Arguments | Out-Null
    if ($LASTEXITCODE -ne 0) {
        throw "sc.exe fallo con codigo ${LASTEXITCODE}: $($Arguments -join ' ')"
    }
}

function Try-StopAndDeleteService([string]$Name) {
    & $Sc stop $Name | Out-Null
    & $Sc delete $Name | Out-Null
    Start-Sleep -Milliseconds 400
}

function Ensure-SafeServiceRoot([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path).TrimEnd('\')
    $expected = [IO.Path]::GetFullPath((Join-Path $env:ProgramData 'InventarioArens\service')).TrimEnd('\')
    if ($full -ne $expected) {
        throw "ServiceRoot debe ser exactamente $expected para evitar borrar otra ruta."
    }
}

function Write-Launcher([string]$Path, [string]$Php, [string]$Backend, [string]$Command, [string]$LogPath, [string]$AppKey, [string]$BootstrapToken) {
    $lines = @(
        '@echo off',
        'setlocal',
        "cd /d `"$Backend`"",
        "set APP_ENV=local",
        "set APP_DEBUG=false",
        "set APP_KEY=$AppKey",
        "set APP_BOOTSTRAP_TOKEN=$BootstrapToken",
        "set APP_URL=http://127.0.0.1:8787",
        "set APP_ALLOWED_ORIGINS_FOR_CSRF=http://127.0.0.1:8788,http://127.0.0.1:8789,http://127.0.0.1:8790,http://localhost:8788,http://localhost:8789,http://localhost:8790",
        "set CORS_ALLOWED_ORIGINS_LOCAL=http://127.0.0.1:8788",
        "set DB_CONNECTION=sqlite",
        "set DB_DATABASE=$DataRoot\inventario.sqlite",
        "set DB_FOREIGN_KEYS=true",
        "set DB_BUSY_TIMEOUT=5000",
        "set DB_JOURNAL_MODE=WAL",
        "set DB_SYNCHRONOUS=NORMAL",
        "set DB_TRANSACTION_MODE=IMMEDIATE",
        "set FILESYSTEM_DISK=local",
        "set LARAVEL_STORAGE_PATH=$DataRoot\storage",
        "set LOCAL_TECHNICAL_CONSOLE_ENABLED=true",
        "set LOG_CHANNEL=stack",
        "set LOG_LEVEL=warning",
        "set QUEUE_CONNECTION=database",
        "set SESSION_DRIVER=database",
        "set SESSION_SECURE_COOKIE=false",
        "set INVENTARIO_SERVICE_MODE=1",
        "set PHP_INI_SCAN_DIR=$DataRoot\php-cert-scan",
        "`"$Php`" artisan $Command >> `"$LogPath`" 2>&1"
    )

    New-Item -ItemType Directory -Path (Split-Path -Parent $Path) -Force | Out-Null
    [IO.File]::WriteAllLines($Path, $lines, [Text.Encoding]::ASCII)
}

function Install-Service([string]$Name, [string]$Launcher, [string]$DisplayName) {
    $binaryPath = "$env:ComSpec /d /s /c `"`"$Launcher`"`""
    New-Service -Name $Name -BinaryPathName $binaryPath -DisplayName $DisplayName -StartupType Automatic | Out-Null
    Invoke-Sc @('failure', $Name, 'reset=', '86400', 'actions=', 'restart/5000/restart/15000/restart/60000')
}

function New-SecretFile([string]$Path, [int]$Bytes) {
    if (Test-Path -LiteralPath $Path -PathType Leaf) { return }
    $data = New-Object byte[] $Bytes
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($data)
    [IO.File]::WriteAllText($Path, ([Convert]::ToBase64String($data) + "`r`n"), [Text.Encoding]::ASCII)
}

function Read-AppKey([string]$Path) {
    $value = (Get-Content -LiteralPath $Path -Raw).Trim()
    if ($value.StartsWith('base64:')) { return $value }

    return "base64:$value"
}

function Install-BackendServices {
    Assert-Administrator
    Assert-Directory $SourceBackendRoot 'el backend Laravel empaquetado'
    Assert-File (Join-Path $SourceBackendRoot 'artisan') 'artisan'
    Assert-Directory $SourcePhpRoot 'el runtime PHP empaquetado'
    $php = Join-Path $SourcePhpRoot $PhpExeName
    Assert-File $php 'php.exe'
    Ensure-SafeServiceRoot $ServiceRoot

    if ($ValidateOnly) {
        Write-Info "Validacion correcta. Backend: $SourceBackendRoot"
        Write-Info "PHP: $php"
        Write-Info "Servicios: $BackendService, $PrinterService"
        return
    }

    New-Item -ItemType Directory -Path $DataRoot, $ServiceRoot, (Join-Path $ServiceRoot 'backend'), (Join-Path $ServiceRoot 'runtime\php'), (Join-Path $ServiceRoot 'logs'), (Join-Path $DataRoot 'storage'), (Join-Path $DataRoot 'php-cert-scan') -Force | Out-Null
    Try-StopAndDeleteService $BackendService
    Try-StopAndDeleteService $PrinterService

    $targetBackend = Join-Path $ServiceRoot 'backend'
    $targetPhp = Join-Path $ServiceRoot 'runtime\php'
    Remove-Item -LiteralPath $targetBackend -Recurse -Force
    Remove-Item -LiteralPath $targetPhp -Recurse -Force
    New-Item -ItemType Directory -Path $targetBackend, $targetPhp -Force | Out-Null
    Copy-Item -Path (Join-Path $SourceBackendRoot '*') -Destination $targetBackend -Recurse -Force
    Copy-Item -Path (Join-Path $SourcePhpRoot '*') -Destination $targetPhp -Recurse -Force

    New-SecretFile (Join-Path $DataRoot 'app.key') 32
    New-SecretFile (Join-Path $DataRoot 'bootstrap.token') 32
    $appKey = Read-AppKey (Join-Path $DataRoot 'app.key')
    $bootstrapToken = (Get-Content -LiteralPath (Join-Path $DataRoot 'bootstrap.token') -Raw).Trim()
    $cert = Join-Path $targetPhp 'cacert.pem'
    if (Test-Path -LiteralPath $cert -PathType Leaf) {
        $escaped = $cert.Replace('\', '\\').Replace('"', '\"')
        @("curl.cainfo = `"$escaped`"", "openssl.cafile = `"$escaped`"") | Set-Content -LiteralPath (Join-Path $DataRoot 'php-cert-scan\zz-cacert.ini') -Encoding ASCII
    }

    $backendLog = Join-Path $ServiceRoot 'logs\backend.log'
    $printerLog = Join-Path $ServiceRoot 'logs\printer.log'
    Write-Launcher (Join-Path $ServiceRoot 'backend.cmd') $php $targetBackend 'serve --host=127.0.0.1 --port=8787' $backendLog $appKey $bootstrapToken
    Write-Launcher (Join-Path $ServiceRoot 'printer.cmd') $php $targetBackend 'printer:serve --port=17777 --bind=127.0.0.1' $printerLog $appKey $bootstrapToken

    $previousPath = $env:PATH
    $env:PATH = "$targetPhp;$previousPath"
    $databasePath = Join-Path $DataRoot 'inventario.sqlite'
    try {
        & $php artisan local:install-sqlite "--database=$databasePath"
        if ($LASTEXITCODE -ne 0) { throw "No se pudo preparar la base SQLite (codigo $LASTEXITCODE)." }
    } finally {
        $env:PATH = $previousPath
    }

    Install-Service $BackendService (Join-Path $ServiceRoot 'backend.cmd') 'InventarioArens Backend'
    Install-Service $PrinterService (Join-Path $ServiceRoot 'printer.cmd') 'InventarioArens Printer'

    $marker = @{
        enabled = $true
        backend_root = $targetBackend
        php_binary = (Join-Path $targetPhp $PhpExeName)
        backend_service = $BackendService
        printer_service = $PrinterService
        installed_at = (Get-Date).ToUniversalTime().ToString('o')
    } | ConvertTo-Json
    Set-Content -LiteralPath (Join-Path $DataRoot 'backend-service.json') -Value $marker -Encoding UTF8

    if (-not $SkipStart) {
        Invoke-Sc @('start', $BackendService)
        Invoke-Sc @('start', $PrinterService)
    }

    Write-Info 'Servicios instalados. La base de datos y los tokens existentes se conservaron.'
}

function Uninstall-BackendServices {
    Assert-Administrator
    Try-StopAndDeleteService $BackendService
    Try-StopAndDeleteService $PrinterService
    $marker = Join-Path $DataRoot 'backend-service.json'
    if (Test-Path -LiteralPath $marker) { Remove-Item -LiteralPath $marker -Force }
    Write-Info 'Servicios detenidos y marcador eliminado. La base SQLite no fue borrada.'
}

Assert-Windows
if ($Uninstall) {
    Uninstall-BackendServices
} else {
    if ([string]::IsNullOrWhiteSpace($SourceBackendRoot) -or [string]::IsNullOrWhiteSpace($SourcePhpRoot)) {
        throw 'Debe indicar -SourceBackendRoot y -SourcePhpRoot.'
    }
    Install-BackendServices
}
