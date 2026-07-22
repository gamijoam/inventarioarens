param(
    [ValidateSet("install", "uninstall", "start", "stop", "status")]
    [string] $Action = "status",
    [string] $TenantSlug = "demo-caracas"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$StateDir = Join-Path $RepoRoot "storage\app\sync-worker"
$SafeTenantSlug = ($TenantSlug.ToLowerInvariant() -replace '[^a-z0-9_-]', '-')
if (!$SafeTenantSlug) {
    $SafeTenantSlug = "default"
}

$TaskName = "SistemaInventarioSync-$SafeTenantSlug"
$LauncherFile = Join-Path $StateDir "sync-task-$SafeTenantSlug.cmd"
$WorkerCmd = Join-Path $PSScriptRoot "sync-worker.cmd"
$HiddenRunner = Join-Path $PSScriptRoot "run-sync-hidden.vbs"

function Ensure-StateDir {
    if (!(Test-Path -LiteralPath $StateDir)) {
        New-Item -ItemType Directory -Path $StateDir | Out-Null
    }
}

function Write-Step([string] $Message) {
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Quote-ProcessArgument([string] $Value) {
    '"' + ($Value.Replace('"', '\"')) + '"'
}

function Invoke-ScheduledTaskCommand([string[]] $Arguments, [switch] $IgnoreFailure, [switch] $QuietOnFailure) {
    $info = [System.Diagnostics.ProcessStartInfo]::new()
    $info.FileName = "schtasks.exe"
    $info.Arguments = ($Arguments | ForEach-Object { Quote-ProcessArgument $_ }) -join " "
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.RedirectStandardError = $true
    $info.CreateNoWindow = $true

    $process = [System.Diagnostics.Process]::Start($info)
    $output = $process.StandardOutput.ReadToEnd()
    $errorOutput = $process.StandardError.ReadToEnd()
    $process.WaitForExit()
    $exitCode = $process.ExitCode

    $combined = (($output.Trim(), $errorOutput.Trim()) | Where-Object { $_ }) -join [Environment]::NewLine
    if ($exitCode -ne 0 -and $QuietOnFailure) {
        Write-Host "Tarea de Windows: NO INSTALADA" -ForegroundColor Yellow
        return
    }

    if ($combined) {
        $combined
    }

    if ($exitCode -ne 0 -and !$IgnoreFailure) {
        exit $exitCode
    }
}

function Write-Launcher {
    Ensure-StateDir

    $content = @"
@echo off
cd /d "$RepoRoot"
call "$WorkerCmd" start -TenantSlug "$TenantSlug"
"@

    Set-Content -LiteralPath $LauncherFile -Value $content -Encoding ASCII
}

function Install-WorkerTask {
    if (!(Test-Path -LiteralPath $WorkerCmd)) {
        throw "No se encontro scripts\sync-worker.cmd."
    }
    if (!(Test-Path -LiteralPath $HiddenRunner)) {
        throw "No se encontro scripts\run-sync-hidden.vbs."
    }

    Write-Launcher

    Write-Step "Instalando tarea automatica de Windows (inicio oculto)"
    try {
        $taskAction = New-ScheduledTaskAction `
            -Execute "wscript.exe" `
            -Argument ("`"" + $HiddenRunner + "`" `"" + $LauncherFile + "`"")
        Register-ScheduledTask -TaskName $TaskName -Trigger $(
            New-ScheduledTaskTrigger -AtStartup
        ) -Action $taskAction -Settings (
            New-ScheduledTaskSettingsSet `
                -AllowStartIfOnBatteries `
                -DontStopIfGoingOnBatteries `
                -StartWhenAvailable `
                -ExecutionTimeLimit (New-TimeSpan -Hours 72)
        ) -Description "Inventario Arens - worker de sincronizacion para $TenantSlug (inicio oculto)." -Force | Out-Null
        $repetition = (Get-ScheduledTask -TaskName $TaskName).Triggers[0].Repetition
        $repetition.Interval = "PT5M"
        $repetition.StopAtDurationEnd = $false
        (Get-ScheduledTask -TaskName $TaskName).Triggers[0].Repetition = $repetition
        Set-ScheduledTask -TaskName $TaskName -Trigger (Get-ScheduledTask -TaskName $TaskName).Triggers | Out-Null
    } catch {
        Write-Host "Aviso: Windows bloqueo la tarea de inicio del sistema. Se instalara como tarea del usuario cada 5 minutos." -ForegroundColor Yellow
        $taskRun = "wscript.exe $HiddenRunner $LauncherFile"
        Invoke-ScheduledTaskCommand @(
            "/Create",
            "/TN", $TaskName,
            "/TR", $taskRun,
            "/SC", "MINUTE",
            "/MO", "5",
            "/F"
        )
    }

    Write-Step "Iniciando sincronizacion ahora"
    & $WorkerCmd start -TenantSlug $TenantSlug
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }

    Write-Host "Sincronizacion automatica instalada." -ForegroundColor Green
    Write-Host "Tarea: $TaskName"
    Write-Host "La tarea verifica el worker cada 5 minutos y lo levanta si esta detenido. Inicio 100% oculto (sin ventana negra)."
}

function Uninstall-WorkerTask {
    Write-Step "Eliminando tarea automatica de Windows"
    Invoke-ScheduledTaskCommand @("/Delete", "/TN", $TaskName, "/F") -IgnoreFailure

    if (Test-Path -LiteralPath $LauncherFile) {
        Remove-Item -LiteralPath $LauncherFile -Force
    }

    Write-Host "Tarea eliminada. Si el worker estaba activo, puedes detenerlo con la opcion Detener." -ForegroundColor Green
}

function Start-WorkerNow {
    & $WorkerCmd start -TenantSlug $TenantSlug
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

function Stop-WorkerNow {
    & $WorkerCmd stop -TenantSlug $TenantSlug
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

function Show-TaskStatus {
    Write-Host "Tarea de Windows: $TaskName"
    Invoke-ScheduledTaskCommand @("/Query", "/TN", $TaskName, "/FO", "LIST") -IgnoreFailure -QuietOnFailure
    Write-Host ""
    & $WorkerCmd status -TenantSlug $TenantSlug
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}

switch ($Action) {
    "install" { Install-WorkerTask }
    "uninstall" { Uninstall-WorkerTask }
    "start" { Start-WorkerNow }
    "stop" { Stop-WorkerNow }
    "status" { Show-TaskStatus }
}
