param(
    [string]$PayloadRoot,
    [string]$MotorRoot = (Join-Path $env:ProgramFiles 'Sistema de Inventario\Motor'),
    [string]$DataRoot = (Join-Path $env:ProgramData 'InventarioArens'),
    [string]$Version = '0.1.0',
    [switch]$ValidateOnly,
    [switch]$Uninstall
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$BackendService = 'SistemaInventarioBackend'
$PrinterService = 'SistemaInventarioPrinter'
$SyncService = 'SistemaInventarioSync'
$LegacyBackend = 'InventarioArensBackend'
$LegacyPrinter = 'InventarioArensPrinter'
$MarkerPath = Join-Path $DataRoot 'backend-service.json'
$LogPath = Join-Path $DataRoot 'motor-install.log'
$Sc = Join-Path $env:SystemRoot 'System32\sc.exe'

function Write-Info([string]$Message) {
    $line = "[$(Get-Date -Format 'yyyy-MM-dd HH:mm:ssK')] $Message"
    if (-not $ValidateOnly) {
        New-Item -ItemType Directory -Path $DataRoot -Force | Out-Null
        Add-Content -LiteralPath $LogPath -Value $line -Encoding UTF8
    }
    Write-Host "[Motor Local] $Message"
}

function Assert-Administrator {
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = [Security.Principal.WindowsPrincipal]::new($identity)
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'El Motor Local debe instalarse como administrador.'
    }
}

function Assert-File([string]$Path, [string]$Description) {
    if (-not (Test-Path -LiteralPath $Path -PathType Leaf)) {
        throw "No se encontro $Description`: $Path"
    }
}

function ConvertTo-XmlValue([string]$Value) {
    return [Security.SecurityElement]::Escape($Value)
}

function New-SecretFile([string]$Path, [int]$Bytes) {
    if (Test-Path -LiteralPath $Path -PathType Leaf) { return }
    $data = New-Object byte[] $Bytes
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($data)
    [IO.File]::WriteAllText($Path, ([Convert]::ToBase64String($data) + "`r`n"), [Text.Encoding]::ASCII)
}

function Protect-SecretFile([string]$Path) {
    $icacls = Join-Path $env:SystemRoot 'System32\icacls.exe'
    & $icacls $Path '/inheritance:r' '/grant:r' '*S-1-5-18:F' '*S-1-5-32-544:F' 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "No se pudieron proteger los permisos de $Path." }
}

function Read-AppKey([string]$Path) {
    $value = (Get-Content -LiteralPath $Path -Raw).Trim()
    if ($value.StartsWith('base64:')) { return $value }
    return "base64:$value"
}

function Get-ServiceObject([string]$Name) {
    return Get-Service -Name $Name -ErrorAction SilentlyContinue
}

function Stop-ServiceSafe([string]$Name) {
    $service = Get-ServiceObject $Name
    if (-not $service) { return }
    if ($service.Status -ne 'Stopped') {
        Stop-Service -Name $Name -Force -ErrorAction SilentlyContinue
        $service.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(20))
    }
}

function Remove-ServiceSafe([string]$Name) {
    Stop-ServiceSafe $Name
    if (-not (Get-ServiceObject $Name)) { return }
    & $Sc delete $Name 2>&1 | Out-Null
    $deadline = (Get-Date).AddSeconds(20)
    while ((Get-ServiceObject $Name) -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 250
    }
    if (Get-ServiceObject $Name) { throw "El servicio $Name sigue marcado para eliminacion." }
}

function Stop-LegacyRuntime {
    foreach ($name in @($LegacyBackend, $LegacyPrinter)) {
        Stop-ServiceSafe $name
        if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
            Stop-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        }
    }
}

function Remove-LegacyRuntime {
    foreach ($name in @($LegacyBackend, $LegacyPrinter)) {
        Remove-ServiceSafe $name
        if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
            Unregister-ScheduledTask -TaskName $name -Confirm:$false
        }
    }
}

function Get-SyncWorkerTasks {
    return @(Get-ScheduledTask -TaskName 'SistemaInventarioSync-*' -ErrorAction SilentlyContinue)
}

function Stop-SyncWorkerTasks([object[]]$Tasks) {
    foreach ($task in $Tasks) {
        Stop-ScheduledTask -TaskName $task.TaskName -ErrorAction SilentlyContinue
    }
}

function Start-SyncWorkerTasks([object[]]$Tasks) {
    foreach ($task in $Tasks) {
        Start-ScheduledTask -TaskName $task.TaskName -ErrorAction SilentlyContinue
    }
}

function Remove-SyncWorkerTasks([object[]]$Tasks) {
    foreach ($task in $Tasks) {
        Stop-ScheduledTask -TaskName $task.TaskName -ErrorAction SilentlyContinue
        Unregister-ScheduledTask -TaskName $task.TaskName -Confirm:$false
    }
}

function Test-ServiceRunning([string]$Name, [int]$TimeoutSeconds = 20) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $service = Get-ServiceObject $Name
        if ($service -and $service.Status -eq 'Running') { return $true }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)

    return $false
}

function Backup-SqliteFiles([string]$Database, [string]$BackupDirectory) {
    New-Item -ItemType Directory -Path $BackupDirectory -Force | Out-Null
    foreach ($suffix in @('', '-wal', '-shm')) {
        $source = $Database + $suffix
        if (Test-Path -LiteralPath $source) {
            Copy-Item -LiteralPath $source -Destination (Join-Path $BackupDirectory ([IO.Path]::GetFileName($source))) -Force
        }
    }
}

function Restore-SqliteFiles([string]$Database, [string]$BackupDirectory) {
    foreach ($suffix in @('', '-wal', '-shm')) {
        $target = $Database + $suffix
        $source = Join-Path $BackupDirectory ([IO.Path]::GetFileName($target))
        if (Test-Path -LiteralPath $source) {
            Copy-Item -LiteralPath $source -Destination $target -Force
        } elseif (Test-Path -LiteralPath $target) {
            Remove-Item -LiteralPath $target -Force
        }
    }
}

function Start-PreviousRuntime($PreviousMarker) {
    if (-not $PreviousMarker) {
        foreach ($name in @($LegacyBackend, $LegacyPrinter)) {
            if (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue) {
                Start-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
            } elseif (Get-ServiceObject $name) {
                Start-Service -Name $name -ErrorAction SilentlyContinue
            }
        }
        return
    }

    $serviceManager = if ($PreviousMarker.PSObject.Properties.Name -contains 'service_manager') {
        $PreviousMarker.service_manager
    } else {
        'scheduled_task'
    }

    if ($serviceManager -eq 'scm') {
        foreach ($property in @('backend_wrapper', 'printer_wrapper', 'sync_wrapper')) {
            if ($PreviousMarker.PSObject.Properties.Name -notcontains $property) { continue }
            $wrapper = $PreviousMarker.$property
            if ($wrapper -and (Test-Path -LiteralPath $wrapper)) { & $wrapper install | Out-Null }
        }
        foreach ($property in @('backend_service', 'printer_service', 'sync_service')) {
            if ($PreviousMarker.PSObject.Properties.Name -notcontains $property) { continue }
            $name = $PreviousMarker.$property
            if ($name -and (Get-ServiceObject $name)) { Start-Service -Name $name -ErrorAction SilentlyContinue }
        }
        return
    }

    foreach ($name in @($PreviousMarker.backend_service, $PreviousMarker.printer_service)) {
        if ($name -and (Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue)) {
            Start-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue
        }
    }
}

function Write-ServiceXml(
    [string]$Path,
    [string]$Id,
    [string]$Name,
    [string]$Description,
    [string]$Php,
    [string]$Backend,
    [string]$Arguments,
    [hashtable]$Environment,
    [string]$ServiceLogPath
) {
    $envLines = @($Environment.GetEnumerator() | Sort-Object Key | ForEach-Object {
        '  <env name="{0}" value="{1}" />' -f (ConvertTo-XmlValue ([string]$_.Key)), (ConvertTo-XmlValue ([string]$_.Value))
    }) -join "`r`n"
    $xml = @"
<service>
  <id>$(ConvertTo-XmlValue $Id)</id>
  <name>$(ConvertTo-XmlValue $Name)</name>
  <description>$(ConvertTo-XmlValue $Description)</description>
  <executable>$(ConvertTo-XmlValue $Php)</executable>
  <arguments>$(ConvertTo-XmlValue $Arguments)</arguments>
  <workingdirectory>$(ConvertTo-XmlValue $Backend)</workingdirectory>
$envLines
  <startmode>Automatic</startmode>
  <delayedAutoStart>true</delayedAutoStart>
  <hidewindow>true</hidewindow>
  <stoptimeout>20 sec</stoptimeout>
  <onfailure action="restart" delay="5 sec" />
  <onfailure action="restart" delay="15 sec" />
  <onfailure action="restart" delay="60 sec" />
  <resetfailure>1 hour</resetfailure>
  <logpath>$(ConvertTo-XmlValue $ServiceLogPath)</logpath>
  <log mode="roll-by-size">
    <sizeThreshold>10240</sizeThreshold>
    <keepFiles>10</keepFiles>
  </log>
</service>
"@
    [IO.File]::WriteAllText($Path, $xml, [Text.UTF8Encoding]::new($false))
}

function Test-HttpHealth([int]$TimeoutSeconds = 45) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        try {
            $response = Invoke-WebRequest -Uri 'http://127.0.0.1:8787/up' -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) { return $true }
        } catch {}
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)
    return $false
}

function Test-TcpPort([int]$Port, [int]$TimeoutSeconds = 20) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    do {
        $client = [Net.Sockets.TcpClient]::new()
        try {
            $task = $client.ConnectAsync('127.0.0.1', $Port)
            if ($task.Wait(750) -and $client.Connected) { return $true }
        } catch {} finally { $client.Dispose() }
        Start-Sleep -Milliseconds 500
    } while ((Get-Date) -lt $deadline)
    return $false
}

function Set-LaravelEnvironment([string]$Php, [string]$Backend, [string]$AppKey, [string]$BootstrapToken) {
    $env:APP_ENV = 'local'
    $env:APP_DEBUG = 'false'
    $env:APP_KEY = $AppKey
    $env:APP_BOOTSTRAP_TOKEN = $BootstrapToken
    $env:APP_URL = 'http://127.0.0.1:8787'
    $env:SYNC_CLOUD_URL = 'https://app.miinventariofacil.com/api'
    $env:LOCAL_TECHNICAL_CONSOLE_CLOUD_URL = 'https://app.miinventariofacil.com/api'
    $env:SYNC_PUBLIC_BASE = 'https://app.miinventariofacil.com'
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = Join-Path $DataRoot 'inventario.sqlite'
    $env:DB_FOREIGN_KEYS = 'true'
    $env:DB_BUSY_TIMEOUT = '5000'
    $env:DB_JOURNAL_MODE = 'WAL'
    $env:DB_SYNCHRONOUS = 'NORMAL'
    $env:DB_TRANSACTION_MODE = 'IMMEDIATE'
    $env:LARAVEL_STORAGE_PATH = Join-Path $DataRoot 'storage'
    $env:PHP_INI_SCAN_DIR = Join-Path $DataRoot 'php-cert-scan'
    $env:INVENTARIO_SERVICE_MODE = '1'
    $env:PATH = "$(Split-Path -Parent $Php);$env:PATH"
}

function Install-Motor {
    if ([string]::IsNullOrWhiteSpace($PayloadRoot)) { throw 'Debe indicar -PayloadRoot.' }
    $Backend = Join-Path $PayloadRoot 'backend'
    $Php = Join-Path $PayloadRoot 'runtime\php\php.exe'
    $WinSw = Join-Path $PayloadRoot 'service\WinSW.exe'
    Assert-File (Join-Path $Backend 'artisan') 'artisan del Motor Local'
    Assert-File $Php 'PHP portable del Motor Local'
    Assert-File $WinSw 'WinSW del Motor Local'

    if ($ValidateOnly) {
        Write-Info "Validacion correcta del Motor $Version en $PayloadRoot"
        return
    }

    Assert-Administrator

    New-Item -ItemType Directory -Path @(
        $DataRoot,
        (Join-Path $DataRoot 'storage\app\sync-worker'),
        (Join-Path $DataRoot 'storage\framework\cache'),
        (Join-Path $DataRoot 'storage\framework\data'),
        (Join-Path $DataRoot 'storage\framework\sessions'),
        (Join-Path $DataRoot 'storage\framework\testing'),
        (Join-Path $DataRoot 'storage\framework\views'),
        (Join-Path $DataRoot 'storage\logs'),
        (Join-Path $DataRoot 'logs\services\backend'),
        (Join-Path $DataRoot 'logs\services\printer'),
        (Join-Path $DataRoot 'logs\services\sync'),
        (Join-Path $DataRoot 'backups'),
        (Join-Path $DataRoot 'php-cert-scan')
    ) -Force | Out-Null

    New-SecretFile (Join-Path $DataRoot 'app.key') 32
    New-SecretFile (Join-Path $DataRoot 'bootstrap.token') 32
    Protect-SecretFile (Join-Path $DataRoot 'app.key')
    Protect-SecretFile (Join-Path $DataRoot 'bootstrap.token')
    $appKey = Read-AppKey (Join-Path $DataRoot 'app.key')
    $bootstrapToken = (Get-Content -LiteralPath (Join-Path $DataRoot 'bootstrap.token') -Raw).Trim()
    $database = Join-Path $DataRoot 'inventario.sqlite'
    $backup = Join-Path $DataRoot "backups\inventario-before-motor-$((Get-Date).ToString('yyyyMMdd-HHmmss'))"
    $previousMarker = if (Test-Path -LiteralPath $MarkerPath) { Get-Content -LiteralPath $MarkerPath -Raw | ConvertFrom-Json } else { $null }
    $workerTasks = Get-SyncWorkerTasks

    $backendWrapper = Join-Path $PayloadRoot 'service\SistemaInventarioBackend.exe'
    $printerWrapper = Join-Path $PayloadRoot 'service\SistemaInventarioPrinter.exe'
    $syncWrapper = Join-Path $PayloadRoot 'service\SistemaInventarioSync.exe'
    Copy-Item -LiteralPath $WinSw -Destination $backendWrapper -Force
    Copy-Item -LiteralPath $WinSw -Destination $printerWrapper -Force
    Copy-Item -LiteralPath $WinSw -Destination $syncWrapper -Force

    $environment = @{
        APP_ENV = 'local'; APP_DEBUG = 'false'
        INVENTARIO_APP_KEY_FILE = (Join-Path $DataRoot 'app.key')
        INVENTARIO_BOOTSTRAP_TOKEN_FILE = (Join-Path $DataRoot 'bootstrap.token')
        APP_URL = 'http://127.0.0.1:8787'; SYNC_CLOUD_URL = 'https://app.miinventariofacil.com/api'
        LOCAL_TECHNICAL_CONSOLE_CLOUD_URL = 'https://app.miinventariofacil.com/api'
        SYNC_PUBLIC_BASE = 'https://app.miinventariofacil.com'
        APP_ALLOWED_ORIGINS_FOR_CSRF = 'http://127.0.0.1:8788,http://127.0.0.1:8789,http://127.0.0.1:8790,http://localhost:8788,http://localhost:8789,http://localhost:8790'
        CORS_ALLOWED_ORIGINS_LOCAL = 'http://127.0.0.1:8788'
        DB_CONNECTION = 'sqlite'; DB_DATABASE = $database; DB_FOREIGN_KEYS = 'true'; DB_BUSY_TIMEOUT = '5000'
        DB_JOURNAL_MODE = 'WAL'; DB_SYNCHRONOUS = 'NORMAL'; DB_TRANSACTION_MODE = 'IMMEDIATE'
        FILESYSTEM_DISK = 'local'; LARAVEL_STORAGE_PATH = (Join-Path $DataRoot 'storage')
        LOCAL_TECHNICAL_CONSOLE_ENABLED = 'true'; LOG_CHANNEL = 'stack'; LOG_LEVEL = 'warning'
        QUEUE_CONNECTION = 'database'; SESSION_DRIVER = 'database'; SESSION_SECURE_COOKIE = 'false'
        INVENTARIO_SERVICE_MODE = '1'; PHP_INI_SCAN_DIR = (Join-Path $DataRoot 'php-cert-scan')
    }

    Write-ServiceXml (Join-Path $PayloadRoot 'service\SistemaInventarioBackend.xml') $BackendService 'Sistema de Inventario - Backend' 'Motor local de inventario y sincronizacion.' $Php $Backend 'artisan serve --host=127.0.0.1 --port=8787' $environment (Join-Path $DataRoot 'logs\services\backend')
    Write-ServiceXml (Join-Path $PayloadRoot 'service\SistemaInventarioPrinter.xml') $PrinterService 'Sistema de Inventario - Impresion' 'Agente local de impresion termica.' $Php $Backend 'artisan printer:serve --port=17777 --bind=127.0.0.1' $environment (Join-Path $DataRoot 'logs\services\printer')
    Write-ServiceXml (Join-Path $PayloadRoot 'service\SistemaInventarioSync.xml') $SyncService 'Sistema de Inventario - Sincronizacion' 'Sincronizacion continua de todas las empresas locales.' $Php $Backend 'artisan sync:daemon-all --interval=15' $environment (Join-Path $DataRoot 'logs\services\sync')

    try {
        Write-Info 'Deteniendo temporalmente el runtime anterior.'
        Stop-SyncWorkerTasks $workerTasks
        Stop-LegacyRuntime
        Remove-ServiceSafe $BackendService
        Remove-ServiceSafe $PrinterService
        Remove-ServiceSafe $SyncService
        if (Test-Path -LiteralPath $database) { Backup-SqliteFiles $database $backup }

        Set-LaravelEnvironment $Php $Backend $appKey $bootstrapToken
        Push-Location $Backend
        try {
            $migrationOutput = @(& $Php artisan local:install-sqlite "--database=$database" 2>&1)
            if ($LASTEXITCODE -ne 0) { throw "La migracion SQLite fallo: $($migrationOutput -join ' ')" }
        } finally { Pop-Location }

        & $backendWrapper install | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'WinSW no pudo instalar el servicio backend.' }
        & $printerWrapper install | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'WinSW no pudo instalar el servicio de impresion.' }
        & $syncWrapper install | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'WinSW no pudo instalar el servicio de sincronizacion.' }
        Start-Service -Name $BackendService
        Start-Service -Name $PrinterService
        Start-Service -Name $SyncService

        if (-not (Test-HttpHealth)) { throw 'El backend no aprobo el health check en 127.0.0.1:8787.' }
        if (-not (Test-TcpPort 17777)) { throw 'El agente no aprobo el health check en 127.0.0.1:17777.' }
        if (-not (Test-ServiceRunning $SyncService)) { throw 'El supervisor de sincronizacion no permanece activo.' }

        $marker = [ordered]@{
            enabled = $true; service_manager = 'scm'; motor_version = $Version; motor_root = $MotorRoot
            backend_root = $Backend; php_binary = $Php; backend_service = $BackendService
            printer_service = $PrinterService; sync_service = $SyncService; backend_wrapper = $backendWrapper
            printer_wrapper = $printerWrapper; sync_wrapper = $syncWrapper
            installed_at = (Get-Date).ToUniversalTime().ToString('o')
        } | ConvertTo-Json
        Set-Content -LiteralPath $MarkerPath -Value $marker -Encoding UTF8
        Set-Content -LiteralPath (Join-Path $MotorRoot 'current.json') -Value $marker -Encoding UTF8
        Remove-LegacyRuntime
        Remove-SyncWorkerTasks $workerTasks
        Write-Info "Motor Local $Version instalado. La base de datos y los tokens existentes se conservaron."
    } catch {
        Write-Info "ERROR; iniciando rollback: $($_.Exception.Message)"
        Remove-ServiceSafe $BackendService
        Remove-ServiceSafe $PrinterService
        Remove-ServiceSafe $SyncService
        if (Test-Path -LiteralPath $backup) { Restore-SqliteFiles $database $backup }
        Start-PreviousRuntime $previousMarker
        Start-SyncWorkerTasks $workerTasks
        throw
    }
}

function Uninstall-Motor {
    Assert-Administrator
    Remove-ServiceSafe $BackendService
    Remove-ServiceSafe $PrinterService
    Remove-ServiceSafe $SyncService
    if (Test-Path -LiteralPath $MarkerPath) { Remove-Item -LiteralPath $MarkerPath -Force }
    Write-Info 'Motor Local desinstalado. SQLite, tokens, storage y respaldos fueron conservados.'
}

try {
    if ($Uninstall) { Uninstall-Motor } else { Install-Motor }
    exit 0
} catch {
    try { Write-Info "ERROR: $($_.Exception.Message)" } catch {}
    Write-Error $_
    exit 1
}
