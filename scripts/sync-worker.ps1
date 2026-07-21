param(
    [ValidateSet("start", "stop", "restart", "status", "run")]
    [string] $Action = "status",
    [string] $PhpPath = "C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe",
    [string] $TenantSlug = "demo-caracas",
    [string] $NodeCode = "LOCAL-01",
    [string] $NodeName = "",
    [string] $InstallationCode = "",
    [string] $CloudUrl = "",
    [string] $Token = "",
    [int] $Interval = 15,
    [int] $Limit = 50,
    [int] $Cycles = 0,
    [switch] $PushOnly,
    [switch] $PullOnly,
    [switch] $NoApply
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$StateDir = Join-Path $RepoRoot "storage\app\sync-worker"
$SafeTenantSlug = ($TenantSlug.ToLowerInvariant() -replace '[^a-z0-9_-]', '-')
if (!$SafeTenantSlug) {
    $SafeTenantSlug = "default"
}
$PidFile = Join-Path $StateDir "sync-worker-$SafeTenantSlug.pid"
$CommandFile = Join-Path $StateDir "sync-worker-$SafeTenantSlug.cmd"
$LogFile = Join-Path $RepoRoot "storage\logs\sync-worker-$SafeTenantSlug.log"

function Normalize-ProcessPathEnvironment {
    $pathValue = [Environment]::GetEnvironmentVariable("Path", "Process")
    $upperPathValue = [Environment]::GetEnvironmentVariable("PATH", "Process")

    if ([string]::IsNullOrWhiteSpace($pathValue) -and ![string]::IsNullOrWhiteSpace($upperPathValue)) {
        $pathValue = $upperPathValue
    }

    if (![string]::IsNullOrWhiteSpace($pathValue)) {
        [Environment]::SetEnvironmentVariable("Path", $pathValue, "Process")
    }

    [Environment]::SetEnvironmentVariable("PATH", $null, "Process")
}

function Write-Step([string] $Message) {
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Fail([string] $Message) {
    throw "[SYNC-WORKER] $Message"
}

function Add-LogSafe([string] $Value) {
    for ($attempt = 1; $attempt -le 3; $attempt++) {
        try {
            $directory = Split-Path -Parent $LogFile
            if (!(Test-Path -LiteralPath $directory)) {
                New-Item -ItemType Directory -Path $directory | Out-Null
            }

            $stream = [System.IO.File]::Open($LogFile, [System.IO.FileMode]::Append, [System.IO.FileAccess]::Write, [System.IO.FileShare]::ReadWrite)
            try {
                $writer = [System.IO.StreamWriter]::new($stream, [System.Text.UTF8Encoding]::new($false))
                try {
                    $writer.WriteLine($Value)
                    $writer.Flush()
                } finally {
                    $writer.Dispose()
                }
            } finally {
                $stream.Dispose()
            }

            return
        } catch {
            Start-Sleep -Milliseconds 250
        }
    }

    Write-Host "Aviso: no se pudo escribir en el log porque esta en uso. La sincronizacion continua." -ForegroundColor Yellow
}

function Quote-ProcessArgument([string] $Value) {
    '"' + ($Value.Replace('"', '\"')) + '"'
}

function Ensure-StateDir {
    if (!(Test-Path -LiteralPath $StateDir)) {
        New-Item -ItemType Directory -Path $StateDir | Out-Null
    }
}

function Read-EnvValue([string] $Name) {
    $envPath = Join-Path $RepoRoot ".env"
    if (!(Test-Path -LiteralPath $envPath)) {
        return ""
    }

    $line = Get-Content -LiteralPath $envPath | Where-Object {
        $_ -match "^\s*$([regex]::Escape($Name))\s*="
    } | Select-Object -First 1

    if (!$line) {
        return ""
    }

    $value = ($line -split "=", 2)[1].Trim()
    if ($value.StartsWith('"') -and $value.EndsWith('"')) {
        $value = $value.Substring(1, $value.Length - 2)
    }

    return $value
}

function Read-WorkerConfig {
    $configPath = Join-Path $StateDir "sync-config.json"
    if (!(Test-Path -LiteralPath $configPath)) {
        return $null
    }

    try {
        $config = Get-Content -LiteralPath $configPath -Raw | ConvertFrom-Json
        if (!$config.tenants) {
            return $null
        }

        $tenantProperty = $config.tenants.PSObject.Properties[$TenantSlug]
        if (!$tenantProperty) {
            return $null
        }

        return $tenantProperty.Value
    } catch {
        Write-Host "Aviso: no se pudo leer sync-config.json. Se usaran argumentos o .env." -ForegroundColor Yellow
        return $null
    }
}

function Get-ConfigValue($Config, [string] $Name, [string] $Fallback = "") {
    if (!$Config) {
        return $Fallback
    }

    $property = $Config.PSObject.Properties[$Name]
    if (!$property -or $null -eq $property.Value) {
        return $Fallback
    }

    $value = [string] $property.Value
    if ([string]::IsNullOrWhiteSpace($value)) {
        return $Fallback
    }

    return $value
}

function Resolve-WorkerSettings {
    $savedConfig = Read-WorkerConfig
    $resolvedNodeCode = if ($NodeCode -and $NodeCode -ne "LOCAL-01") { $NodeCode } else { Get-ConfigValue $savedConfig "node_code" $NodeCode }
    $resolvedNodeName = if ($NodeName) { $NodeName } else { Get-ConfigValue $savedConfig "node_name" $resolvedNodeCode }
    $resolvedInstallation = if ($InstallationCode) { $InstallationCode } else { Get-ConfigValue $savedConfig "installation_code" $resolvedNodeCode }
    $resolvedCloudUrl = if ($CloudUrl) { $CloudUrl } else { Get-ConfigValue $savedConfig "cloud_url" (Read-EnvValue "SYNC_CLOUD_URL") }
    $resolvedToken = if ($Token) { $Token } else { Get-ConfigValue $savedConfig "token" (Read-EnvValue "SYNC_CLOUD_TOKEN") }
    $resolvedInterval = if ($Interval -ne 15) { $Interval } elseif ($savedConfig -and $savedConfig.PSObject.Properties["interval"]) { [int] $savedConfig.interval } else { $Interval }

    return [pscustomobject]@{
        NodeCode = $resolvedNodeCode
        NodeName = $resolvedNodeName
        InstallationCode = $resolvedInstallation
        CloudUrl = $resolvedCloudUrl
        Token = $resolvedToken
        Interval = $resolvedInterval
    }
}

function Get-WorkerProcess {
    if (!(Test-Path -LiteralPath $PidFile)) {
        return $null
    }

    $pidText = (Get-Content -LiteralPath $PidFile -TotalCount 1).Trim()
    if (!$pidText) {
        return $null
    }

    $processId = 0
    if (![int]::TryParse($pidText, [ref] $processId)) {
        return $null
    }

    return Get-Process -Id $processId -ErrorAction SilentlyContinue
}

function Show-Status {
    $process = Get-WorkerProcess
    if ($process) {
        Write-Host "Worker de sincronizacion: ACTIVO" -ForegroundColor Green
        Write-Host "Empresa: $TenantSlug"
        Write-Host "PID: $($process.Id)"
        Write-Host "Log: $LogFile"
        return
    }

    if (Test-Path -LiteralPath $PidFile) {
        Remove-Item -LiteralPath $PidFile -Force -ErrorAction SilentlyContinue
    }

    Write-Host "Worker de sincronizacion: DETENIDO" -ForegroundColor Yellow
    Write-Host "Empresa: $TenantSlug"
    Write-Host "Log: $LogFile"
}

function Stop-Worker {
    $process = Get-WorkerProcess
    if (!$process) {
        Write-Host "No hay worker activo." -ForegroundColor Yellow
        if (Test-Path -LiteralPath $PidFile) {
            Remove-Item -LiteralPath $PidFile -Force
        }
        return
    }

    Write-Step "Deteniendo worker PID $($process.Id)"
    Stop-Process -Id $process.Id -Force
    Start-Sleep -Milliseconds 500

    if (Test-Path -LiteralPath $PidFile) {
        Remove-Item -LiteralPath $PidFile -Force
    }

    Write-Host "Worker detenido." -ForegroundColor Green
}

function Start-Worker {
    Ensure-StateDir

    if (!(Test-Path -LiteralPath $PhpPath)) {
        Fail "No se encontro PHP en: $PhpPath"
    }

    $current = Get-WorkerProcess
    if ($current) {
        Write-Host "Ya existe un worker activo. PID: $($current.Id)" -ForegroundColor Yellow
        Show-Status
        return
    }

    $settings = Resolve-WorkerSettings
    $effectiveCloudUrl = $settings.CloudUrl
    $effectiveToken = $settings.Token
    $effectiveNodeCode = $settings.NodeCode
    $effectiveNodeName = $settings.NodeName
    $effectiveInstallationCode = $settings.InstallationCode

    if (!$effectiveCloudUrl) {
        Fail "Falta CloudUrl. Pasalo con -CloudUrl o configura SYNC_CLOUD_URL en .env."
    }

    if (!$effectiveToken) {
        Fail "Falta Token. Pasalo con -Token o configura SYNC_CLOUD_TOKEN en .env."
    }

    Normalize-ProcessPathEnvironment

    $safeInterval = [Math]::Max(5, $settings.Interval)
    $safeLimit = [Math]::Max(1, [Math]::Min(200, $Limit))
    $safeCycles = [Math]::Max(0, $Cycles)
    $extraFlags = @()
    if ($PushOnly) { $extraFlags += "--push-only" }
    if ($PullOnly) { $extraFlags += "--pull-only" }
    if ($NoApply) { $extraFlags += "--no-apply" }

    $arguments = @(
        "artisan",
        "sync:daemon",
        $TenantSlug,
        "--node=$effectiveNodeCode",
        "--name=$effectiveNodeName",
        "--installation=$effectiveInstallationCode",
        "--interval=$safeInterval",
        "--limit=$safeLimit",
        "--cycles=$safeCycles"
    ) + $extraFlags

    $argumentLine = ($arguments | ForEach-Object { Quote-ProcessArgument $_ }) -join " "
    $commandLine = (Quote-ProcessArgument $PhpPath) + " " + $argumentLine
    $cmd = @"
@echo off
cd /d "$RepoRoot"
echo ==================================================>> "$LogFile"
echo Worker iniciado %date% %time%>> "$LogFile"
echo Empresa $TenantSlug>> "$LogFile"
$commandLine >> "$LogFile" 2>>&1
"@

    Set-Content -LiteralPath $CommandFile -Value $cmd -Encoding ASCII
    $ErrorLogFile = [System.IO.Path]::ChangeExtension($LogFile, ".error.log")
    Add-LogSafe "=================================================="
    Add-LogSafe "Worker iniciado $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    Add-LogSafe "Empresa $TenantSlug"

    Write-Step "Iniciando worker de sincronizacion"
    $previousCloudUrl = [Environment]::GetEnvironmentVariable("SYNC_CLOUD_URL", "Process")
    $previousToken = [Environment]::GetEnvironmentVariable("SYNC_CLOUD_TOKEN", "Process")
    try {
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_URL", $effectiveCloudUrl, "Process")
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_TOKEN", $effectiveToken, "Process")
        $process = Start-Process -FilePath $PhpPath -ArgumentList $argumentLine -WorkingDirectory $RepoRoot -WindowStyle Hidden -PassThru -RedirectStandardOutput $LogFile -RedirectStandardError $ErrorLogFile
        Set-Content -LiteralPath $PidFile -Value ([string] $process.Id) -Encoding ASCII
    } finally {
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_URL", $previousCloudUrl, "Process")
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_TOKEN", $previousToken, "Process")
    }

    Write-Host "Worker iniciado." -ForegroundColor Green
    Write-Host "PID: $($process.Id)"
    Write-Host "Log: $LogFile"
}

function Invoke-RunOnce {
    Ensure-StateDir

    if (!(Test-Path -LiteralPath $PhpPath)) {
        Fail "No se encontro PHP en: $PhpPath"
    }

    $settings = Resolve-WorkerSettings
    $effectiveCloudUrl = $settings.CloudUrl
    $effectiveToken = $settings.Token
    $effectiveNodeCode = $settings.NodeCode
    $effectiveNodeName = $settings.NodeName
    $effectiveInstallationCode = $settings.InstallationCode

    if (!$effectiveCloudUrl) {
        Fail "Falta CloudUrl. Pasalo con -CloudUrl o configura SYNC_CLOUD_URL en .env."
    }

    if (!$effectiveToken) {
        Fail "Falta Token. Pasalo con -Token o configura SYNC_CLOUD_TOKEN en .env."
    }

    Normalize-ProcessPathEnvironment

    $safeLimit = [Math]::Max(1, [Math]::Min(200, $Limit))
    $extraFlags = @()
    if ($PushOnly) { $extraFlags += "--push-only" }
    if ($PullOnly) { $extraFlags += "--pull-only" }
    if ($NoApply) { $extraFlags += "--no-apply" }

    Write-Step "Ejecutando sincronizacion inmediata"

    $previousCloudUrl = [Environment]::GetEnvironmentVariable("SYNC_CLOUD_URL", "Process")
    $previousToken = [Environment]::GetEnvironmentVariable("SYNC_CLOUD_TOKEN", "Process")
    try {
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_URL", $effectiveCloudUrl, "Process")
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_TOKEN", $effectiveToken, "Process")

        $arguments = @(
            "artisan",
            "sync:run",
            $TenantSlug,
            "--node=$effectiveNodeCode",
            "--name=$effectiveNodeName",
            "--installation=$effectiveInstallationCode",
            "--limit=$safeLimit"
        ) + $extraFlags

        Push-Location $RepoRoot
        try {
            $output = & $PhpPath @arguments 2>&1
            $exitCode = $LASTEXITCODE
        } finally {
            Pop-Location
        }

        $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
        Add-LogSafe "=================================================="
        Add-LogSafe "Sincronizacion manual $timestamp"
        Add-LogSafe ($output | Out-String)

        $output

        if ($exitCode -ne 0) {
            exit $exitCode
        }
    } finally {
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_URL", $previousCloudUrl, "Process")
        [Environment]::SetEnvironmentVariable("SYNC_CLOUD_TOKEN", $previousToken, "Process")
    }
}

try {
switch ($Action) {
    "start" { Start-Worker }
    "stop" { Stop-Worker }
    "restart" {
        Stop-Worker
        Start-Worker
    }
    "status" { Show-Status }
    "run" { Invoke-RunOnce }
}
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
