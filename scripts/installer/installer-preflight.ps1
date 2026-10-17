[CmdletBinding()]
param(
    [string]$InstallDir,
    [string]$SetupDir,
    [string]$OutputFile,
    [int]$Port = 8787,
    [switch]$FindCompanyZip,
    [switch]$StopOnly,
    [switch]$CheckPort,
    [switch]$WaitForBackend
)

$ErrorActionPreference = 'Stop'

function Get-InstallRoot {
    if ([string]::IsNullOrWhiteSpace($InstallDir)) {
        throw 'No se indicó la carpeta actual de BalanzaPro.'
    }
    return [System.IO.Path]::GetFullPath($InstallDir).TrimEnd('\') + '\'
}

function Find-CompanyPackage {
    if ([string]::IsNullOrWhiteSpace($SetupDir) -or [string]::IsNullOrWhiteSpace($OutputFile)) {
        throw 'No se indicó dónde buscar el ZIP de la empresa.'
    }
    $matches = @(Get-ChildItem -LiteralPath $SetupDir -File -Filter 'BalanzaPro-datos-*.zip' -ErrorAction SilentlyContinue)
    if ($matches.Count -eq 0) {
        throw 'Falta el ZIP privado de la empresa. Descárgalo desde Configuración de empresa y colócalo junto a BalanzaPro-Setup.exe.'
    }
    if ($matches.Count -ne 1) {
        throw 'Hay más de un ZIP de BalanzaPro junto al Setup. Deja solo los datos de la empresa que vas a instalar.'
    }
    $encoding = New-Object System.Text.UTF8Encoding($false)
    Copy-Item -LiteralPath $matches[0].FullName -Destination $OutputFile -Force
}

function Stop-OwnProcesses {
    $root = Get-InstallRoot
    $names = @(
        'MotorLocal.exe',
        'BalanzaPro-POS.exe',
        'BalanzaPro-Administrativo.exe',
        'frankenphp.exe',
        'php.exe',
        'printer-agent.exe',
        'scale-agent.exe'
    )

    $processes = @(Get-CimInstance Win32_Process | Where-Object {
        $_.Name -in $names -and
        $_.ExecutablePath -and
        $_.ExecutablePath.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)
    })

    $motor = @($processes | Where-Object { $_.Name -eq 'MotorLocal.exe' })
    foreach ($item in $motor) {
        Stop-Process -Id $item.ProcessId -Force -ErrorAction SilentlyContinue
    }
    Start-Sleep -Milliseconds 500

    foreach ($item in $processes | Where-Object { $_.Name -ne 'MotorLocal.exe' }) {
        Stop-Process -Id $item.ProcessId -Force -ErrorAction SilentlyContinue
    }

    for ($attempt = 0; $attempt -lt 20; $attempt++) {
        $remaining = @(Get-CimInstance Win32_Process | Where-Object {
            $_.Name -in $names -and
            $_.ExecutablePath -and
            $_.ExecutablePath.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)
        })
        if ($remaining.Count -eq 0) { break }
        Start-Sleep -Milliseconds 250
    }
    if ($remaining.Count -gt 0) {
        throw 'No se pudieron cerrar todos los procesos de la instalación actual.'
    }
}

function Assert-PortAvailable {
    $root = Get-InstallRoot
    $connections = @(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
    $owners = @($connections | Select-Object -ExpandProperty OwningProcess -Unique)
    foreach ($owner in $owners) {
        $process = Get-CimInstance Win32_Process -Filter "ProcessId = $owner" -ErrorAction SilentlyContinue
        $path = [string]$process.ExecutablePath
        if (-not $path -or -not $path.StartsWith($root, [System.StringComparison]::OrdinalIgnoreCase)) {
            $description = if ($process) { "$($process.Name) (PID $owner)" } else { "PID $owner" }
            throw "El puerto $Port ya está ocupado por $description. Cierra el otro Motor Local antes de instalar BalanzaPro."
        }
    }
}

function Wait-ForBackend {
    $uri = 'http://127.0.0.1:8788/up'
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $uri -TimeoutSec 2
            if ($response.StatusCode -eq 200) { return }
        } catch {
            Start-Sleep -Milliseconds 750
        }
    }
    throw 'El Motor Local no inició el backend. La instalación se recuperará y tus datos permanecerán guardados.'
}

try {
    if ($FindCompanyZip) {
        Find-CompanyPackage
        exit 0
    }
    if ($WaitForBackend) {
        Wait-ForBackend
        exit 0
    }
    Stop-OwnProcesses
    if ($CheckPort) { Assert-PortAvailable }
    exit 0
} catch {
    [Console]::Error.WriteLine($_.Exception.Message)
    exit 1
}
