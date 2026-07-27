param(
    [Parameter(Mandatory = $true)]
    [string] $AppRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$php = Join-Path $AppRoot "runtime\php\php.exe"
$envFile = Join-Path $AppRoot ".env"
$envTemplate = Join-Path $AppRoot ".env.local-sqlite.example"
$dataRoot = Join-Path $env:ProgramData "InventarioArens"
$databasePath = Join-Path $dataRoot "inventario.sqlite"
$logRoot = Join-Path $dataRoot "logs"
$installLog = Join-Path $logRoot "install-local.log"

function New-RandomToken {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }

    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function New-LaravelAppKey {
    $bytes = New-Object byte[] 32
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        $rng.GetBytes($bytes)
    } finally {
        $rng.Dispose()
    }

    return 'base64:' + [Convert]::ToBase64String($bytes)
}

function Grant-LocalDataAccess {
    param([Parameter(Mandatory = $true)][string] $Path)

    $acl = Get-Acl -LiteralPath $Path
    $users = New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-545')
    $inheritance = [System.Security.AccessControl.InheritanceFlags]::ContainerInherit -bor [System.Security.AccessControl.InheritanceFlags]::ObjectInherit
    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule($users, 'Modify', $inheritance, 'None', 'Allow')

    $acl.SetAccessRule($rule)
    Set-Acl -LiteralPath $Path -AclObject $acl
}

function Enable-PortablePhpExtensions {
    param(
        [Parameter(Mandatory = $true)][string] $PhpDirectory,
        [Parameter(Mandatory = $true)][string] $CaBundleSource
    )

    $phpIni = Join-Path $PhpDirectory "php.ini"
    if (!(Test-Path -LiteralPath $phpIni)) {
        $productionIni = Join-Path $PhpDirectory "php.ini-production"
        if (!(Test-Path -LiteralPath $productionIni)) {
            throw "No se encontro php.ini-production en $PhpDirectory."
        }

        Copy-Item -LiteralPath $productionIni -Destination $phpIni
    }

    if (!(Test-Path -LiteralPath $CaBundleSource)) {
        throw "No se encontro el paquete de certificados HTTPS: $CaBundleSource."
    }

    $caBundleDestination = Join-Path $env:ProgramData "InventarioArens\certificates\cacert.pem"
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $caBundleDestination) | Out-Null
    Copy-Item -LiteralPath $CaBundleSource -Destination $caBundleDestination -Force

    $iniText = Get-Content -LiteralPath $phpIni -Raw
    $iniText = [regex]::Replace($iniText, '(?mi)^\s*;?\s*extension_dir\s*=.*$', '')
    $iniText += "`r`nextension_dir = `"ext`"`r`n"

    foreach ($extension in @('curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'zip')) {
        $escaped = [regex]::Escape($extension)
        $iniText = [regex]::Replace($iniText, "(?mi)^\s*;?\s*extension\s*=\s*$escaped\s*$", '')
        $iniText += "extension=$extension`r`n"
    }

    $iniText = [regex]::Replace($iniText, '(?mi)^\s*;?\s*(curl\.cainfo|openssl\.cafile)\s*=.*\r?\n?', '')
    $caBundleIniPath = $caBundleDestination.Replace('\', '/')
    $iniText += "curl.cainfo = `"$caBundleIniPath`"`r`n"
    $iniText += "openssl.cafile = `"$caBundleIniPath`"`r`n"

    Set-Content -LiteralPath $phpIni -Value $iniText -Encoding UTF8

    $modules = & (Join-Path $PhpDirectory "php.exe") -m 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw "PHP portable no pudo cargar sus extensiones. Revisa $phpIni."
    }

    $loaded = @($modules | ForEach-Object { $_.ToString().Trim().ToLowerInvariant() })
    $missing = New-Object System.Collections.Generic.List[string]
    foreach ($requiredModule in @('curl', 'openssl', 'pdo_sqlite', 'sqlite3')) {
        if ($requiredModule -notin $loaded) {
            $missing.Add($requiredModule)
        }
    }
    if ($missing.Count -gt 0) {
        throw "PHP portable no tiene habilitadas las extensiones requeridas: $($missing -join ', ')."
    }
}

if (!(Test-Path -LiteralPath $php)) {
    throw "No se encontro PHP portable en $php."
}

$caBundleSource = Join-Path $AppRoot "installer\windows\cacert.pem"
Enable-PortablePhpExtensions -PhpDirectory (Split-Path -Parent $php) -CaBundleSource $caBundleSource

$phpConfigRoot = Join-Path $env:ProgramData "InventarioArens\php-conf"
New-Item -ItemType Directory -Force -Path $phpConfigRoot | Out-Null
@(
    'curl.cainfo = "C:/ProgramData/InventarioArens/certificates/cacert.pem"'
    'openssl.cafile = "C:/ProgramData/InventarioArens/certificates/cacert.pem"'
) | Set-Content -LiteralPath (Join-Path $phpConfigRoot "99-inventarioarens-https.ini") -Encoding ASCII

New-Item -ItemType Directory -Force -Path $dataRoot | Out-Null
Grant-LocalDataAccess -Path $dataRoot
New-Item -ItemType Directory -Force -Path $logRoot | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $dataRoot "backups") | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $AppRoot "storage\app\sync-worker") | Out-Null

# Laravel consulta esta variable antes de cargar .env. Mantiene logs, cache,
# SQLite y archivos operativos fuera de Program Files, que Windows protege.
$env:LARAVEL_STORAGE_PATH = $dataRoot

"[$(Get-Date -Format s)] Instalando Inventario Arens local en $AppRoot" | Set-Content -LiteralPath $installLog -Encoding UTF8

if (!(Test-Path -LiteralPath $envFile)) {
    Copy-Item -LiteralPath $envTemplate -Destination $envFile
}

$envText = Get-Content -LiteralPath $envFile -Raw
$replacements = @{
    'DB_CONNECTION=.*' = 'DB_CONNECTION=sqlite'
    'DB_DATABASE=.*' = "DB_DATABASE=$databasePath"
    'APP_URL=.*' = 'APP_URL=http://127.0.0.1:8787'
    'LARAVEL_STORAGE_PATH=.*' = "LARAVEL_STORAGE_PATH=$dataRoot"
    'LOCAL_TECHNICAL_CONSOLE_ENABLED=.*' = 'LOCAL_TECHNICAL_CONSOLE_ENABLED=true'
    'LOCAL_TECHNICAL_CONSOLE_CLOUD_URL=.*' = 'LOCAL_TECHNICAL_CONSOLE_CLOUD_URL=https://app.miinventariofacil.com/api'
}
foreach ($pattern in $replacements.Keys) {
    $replacement = $replacements[$pattern]
    if ($envText -match "(?m)^$pattern$") {
        $envText = [regex]::Replace($envText, "(?m)^$pattern$", $replacement)
    } else {
        $envText += "`r`n$replacement"
    }
}

if ($envText -match '(?m)^APP_STORAGE_PATH=.*$') {
    $envText = [regex]::Replace($envText, '(?m)^APP_STORAGE_PATH=.*\r?\n?', '')
}

if ($envText -notmatch '(?m)^APP_KEY=') {
    $envText = "APP_KEY=$(New-LaravelAppKey)`r`n" + $envText
} elseif ($envText -match '(?m)^APP_KEY=\s*$') {
    $appKey = New-LaravelAppKey
    $envText = [regex]::Replace($envText, '(?m)^APP_KEY=\s*$', "APP_KEY=$appKey")
}

if ($envText -notmatch '(?m)^APP_BOOTSTRAP_TOKEN=') {
    $bootstrapToken = New-RandomToken
    $envText += "`r`nAPP_BOOTSTRAP_TOKEN=$bootstrapToken"
} elseif ($envText -match '(?m)^APP_BOOTSTRAP_TOKEN=\s*$') {
    $bootstrapToken = New-RandomToken
    $envText = [regex]::Replace($envText, '(?m)^APP_BOOTSTRAP_TOKEN=\s*$', "APP_BOOTSTRAP_TOKEN=$bootstrapToken")
}
Set-Content -LiteralPath $envFile -Value $envText -Encoding UTF8

Push-Location $AppRoot
try {
    $configuredEnv = Get-Content -LiteralPath $envFile -Raw
    if ($configuredEnv -notmatch '(?m)^APP_KEY=base64:[A-Za-z0-9+/=]+\s*$') {
        throw "APP_KEY no se genero correctamente. Revisa $installLog."
    }

    "[$(Get-Date -Format s)] Preparando SQLite en $databasePath" | Add-Content -LiteralPath $installLog
    & $php artisan local:install-sqlite --database=$databasePath
    if ($LASTEXITCODE -ne 0) { throw "No se pudo preparar SQLite." }

    $databaseFile = Get-Item -LiteralPath $databasePath -ErrorAction Stop
    if ($databaseFile.Length -le 0) {
        throw "SQLite quedo vacio en $databasePath. Revisa $installLog."
    }

    "[$(Get-Date -Format s)] SQLite listo. Bytes: $($databaseFile.Length)" | Add-Content -LiteralPath $installLog
} finally {
    Pop-Location
}

Write-Host "Inventario Arens instalado en $AppRoot" -ForegroundColor Green
Write-Host "Base local: $databasePath"
Write-Host "Datos locales: $dataRoot"
Write-Host "Log instalacion: $installLog"
Write-Host "Siguiente paso: ejecutar start-inventario.ps1 y vincular una empresa."
