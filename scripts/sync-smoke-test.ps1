param(
    [string] $PhpPath = "C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe",
    [string] $TenantSlug = "demo-caracas",
    [string] $NodeCode = "LOCAL-SMOKE-01",
    [string] $LocalDbHost = "127.0.0.1",
    [int] $LocalDbPort = 5434,
    [string] $LocalDbName = "inventory_arens",
    [string] $LocalDbUser = "inventory_arens",
    [string] $LocalDbPassword = "secret",
    [string] $CloudDbHost = "217.216.80.158",
    [int] $CloudDbPort = 5432,
    [string] $CloudDbName = "inventory_arens",
    [string] $CloudDbUser = "postgres",
    [Parameter(Mandatory = $true)]
    [string] $CloudDbPassword,
    [int] $CloudApiPort = 8010,
    [string] $CloudApiUrl = "",
    [switch] $KeepCloudApi
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$TempDir = Join-Path $RepoRoot "storage\app\sync-smoke"
if ([string]::IsNullOrWhiteSpace($CloudApiUrl)) {
    $CloudApiUrl = "http://127.0.0.1:$CloudApiPort/api"
}
$UseExternalCloudApi = $CloudApiUrl -notlike "http://127.0.0.1:*" -and $CloudApiUrl -notlike "http://localhost:*"

function Write-Step([string] $Message) {
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Fail([string] $Message) {
    throw "[SYNC-SMOKE] $Message"
}

function Set-EnvValue([string] $Name, [string] $Value) {
    [Environment]::SetEnvironmentVariable($Name, $Value, "Process")
}

function Save-Env([string[]] $Names) {
    $state = @{}
    foreach ($name in $Names) {
        $state[$name] = [Environment]::GetEnvironmentVariable($name, "Process")
    }
    return $state
}

function Restore-Env($State) {
    foreach ($name in $State.Keys) {
        [Environment]::SetEnvironmentVariable($name, $State[$name], "Process")
    }
}

function Invoke-CloudArtisan([string[]] $Arguments) {
    $names = @("APP_ENV", "DB_CONNECTION", "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD")
    $old = Save-Env $names
    try {
        Set-EnvValue "APP_ENV" "cloudtest"
        Set-EnvValue "DB_CONNECTION" "pgsql"
        Set-EnvValue "DB_HOST" $CloudDbHost
        Set-EnvValue "DB_PORT" ([string] $CloudDbPort)
        Set-EnvValue "DB_DATABASE" $CloudDbName
        Set-EnvValue "DB_USERNAME" $CloudDbUser
        Set-EnvValue "DB_PASSWORD" $CloudDbPassword

        Push-Location $RepoRoot
        try {
            & $PhpPath "artisan" @Arguments
            if ($LASTEXITCODE -ne 0) {
                Fail "Artisan remoto fallo: $($Arguments -join ' ')"
            }
        } finally {
            Pop-Location
        }
    } finally {
        Restore-Env $old
    }
}

function Invoke-PhpTemp([string] $Name, [string] $Code) {
    if (!(Test-Path -LiteralPath $TempDir)) {
        New-Item -ItemType Directory -Path $TempDir | Out-Null
    }

    $path = Join-Path $TempDir $Name
    Set-Content -LiteralPath $path -Value $Code -Encoding ASCII
    try {
        $output = & $PhpPath $path 2>&1
        $exitCode = $LASTEXITCODE
        if ($output) {
            $output
        }

        if ($exitCode -ne 0) {
            Fail "PHP temporal fallo: $Name"
        }
    } finally {
        if (Test-Path -LiteralPath $path) {
            Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
        }
    }
}

function Wait-Port([int] $Port, [int] $TimeoutSeconds = 20) {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $client = New-Object System.Net.Sockets.TcpClient
        try {
            $task = $client.ConnectAsync("127.0.0.1", $Port)
            if ($task.Wait(600) -and $client.Connected) {
                return $true
            }
        } catch {
        } finally {
            $client.Dispose()
        }

        Start-Sleep -Milliseconds 500
    }

    return $false
}

function Test-PortOpen([int] $Port) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $task = $client.ConnectAsync("127.0.0.1", $Port)
        return ($task.Wait(500) -and $client.Connected)
    } catch {
        return $false
    } finally {
        $client.Dispose()
    }
}

function Stop-PortOwner([int] $Port) {
    try {
        $connections = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
        foreach ($connection in $connections) {
            if ($connection.OwningProcess) {
                Stop-Process -Id $connection.OwningProcess -Force -ErrorAction SilentlyContinue
            }
        }
    } catch {
        Write-Warning "No se pudo cerrar automaticamente el proceso del puerto $Port. Revisa si quedo una API temporal abierta."
    }
}

if (!(Test-Path -LiteralPath $PhpPath)) {
    Fail "No se encontro PHP en: $PhpPath"
}

Write-Step "Validando conexion directa a PostgreSQL nube"
$env:SMOKE_CLOUD_HOST = $CloudDbHost
$env:SMOKE_CLOUD_PORT = [string] $CloudDbPort
$env:SMOKE_CLOUD_DB = $CloudDbName
$env:SMOKE_CLOUD_USER = $CloudDbUser
$env:SMOKE_CLOUD_PASSWORD = $CloudDbPassword

$probe = @'
<?php
$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=15;sslmode=disable',
    getenv('SMOKE_CLOUD_HOST'),
    getenv('SMOKE_CLOUD_PORT'),
    getenv('SMOKE_CLOUD_DB')
);
$pdo = new PDO($dsn, getenv('SMOKE_CLOUD_USER'), getenv('SMOKE_CLOUD_PASSWORD'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 8,
]);
echo "conexion_nube=ok\n";
echo "base=".$pdo->query('select current_database()')->fetchColumn()."\n";
'@
try {
    Invoke-PhpTemp "probe-cloud.php" $probe
} catch {
    Write-Host ""
    Write-Host "No se pudo conectar al PostgreSQL del VPS desde esta maquina." -ForegroundColor Yellow
    Write-Host "Verifica que estes sin VPN, que el puerto 5432 responda y que HeidiSQL conecte directo antes de repetir." -ForegroundColor Yellow
    throw
}

Write-Step "Aplicando migraciones pendientes en la base nube"
Invoke-CloudArtisan @("migrate", "--force")

Write-Step "Preparando datos minimos de prueba en local y nube"
$env:SMOKE_LOCAL_HOST = $LocalDbHost
$env:SMOKE_LOCAL_PORT = [string] $LocalDbPort
$env:SMOKE_LOCAL_DB = $LocalDbName
$env:SMOKE_LOCAL_USER = $LocalDbUser
$env:SMOKE_LOCAL_PASSWORD = $LocalDbPassword
$env:SMOKE_TENANT_SLUG = $TenantSlug

$fixture = @'
<?php
function pdo_from_env(string $prefix): PDO {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s;connect_timeout=15;sslmode=disable',
        getenv($prefix.'_HOST'),
        getenv($prefix.'_PORT'),
        getenv($prefix.'_DB')
    );

    return new PDO($dsn, getenv($prefix.'_USER'), getenv($prefix.'_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function scalar(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function exec_sql(PDO $pdo, string $sql, array $params = []): void {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
}

function uuid_v4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function ensure_tenant(PDO $pdo, string $slug): int {
    $now = date('Y-m-d H:i:s');
    $name = ucwords(str_replace('-', ' ', $slug));
    $stmt = $pdo->prepare(
        "insert into tenants (name, slug, status, plan, created_at, updated_at)
         values (:name, :slug, 'active', 'smoke', :now, :now)
         on conflict (slug) do update set name = excluded.name, status = 'active', updated_at = excluded.updated_at
         returning id"
    );
    $stmt->execute(['name' => $name, 'slug' => $slug, 'now' => $now]);

    return (int) $stmt->fetchColumn();
}

function ensure_user(PDO $pdo, int $tenantId): int {
    $now = date('Y-m-d H:i:s');
    $email = 'sync.smoke@demo.test';
    $stmt = $pdo->prepare(
        "insert into users (name, email, password, created_at, updated_at)
         values ('Usuario Sync Smoke', :email, :password, :now, :now)
         on conflict (email) do update set name = excluded.name, updated_at = excluded.updated_at
         returning id"
    );
    $stmt->execute([
        'email' => $email,
        'password' => password_hash('password', PASSWORD_BCRYPT),
        'now' => $now,
    ]);
    $userId = (int) $stmt->fetchColumn();

    exec_sql(
        $pdo,
        "insert into tenant_user (tenant_id, user_id, status, created_at, updated_at)
         values (:tenant_id, :user_id, 'active', :now, :now)
         on conflict (tenant_id, user_id) do update set status = 'active', updated_at = excluded.updated_at",
        ['tenant_id' => $tenantId, 'user_id' => $userId, 'now' => $now]
    );

    return $userId;
}

function issue_token(PDO $pdo, int $tenantId, int $userId): string {
    $now = date('Y-m-d H:i:s');
    $token = bin2hex(random_bytes(40));
    exec_sql(
        $pdo,
        "insert into auth_tokens (tenant_id, user_id, name, token_hash, abilities, expires_at, created_at, updated_at)
         values (:tenant_id, :user_id, 'sync-smoke', :hash, :abilities, :expires_at, :now, :now)",
        [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'hash' => hash('sha256', $token),
            'abilities' => json_encode(['*']),
            'expires_at' => date('Y-m-d H:i:s', strtotime('+7 days')),
            'now' => $now,
        ]
    );

    return $token;
}

function ensure_product(PDO $pdo, int $tenantId, string $name, float $basePrice): int {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "insert into products (tenant_id, name, sku, tracking_type, base_price, sale_currency, is_active, created_at, updated_at)
         values (:tenant_id, :name, 'SYNC-SMOKE-001', 'quantity', :base_price, 'USD', true, :now, :now)
         on conflict (tenant_id, sku) do update set
            name = excluded.name,
            tracking_type = excluded.tracking_type,
            base_price = excluded.base_price,
            sale_currency = excluded.sale_currency,
            is_active = true,
            updated_at = excluded.updated_at
         returning id"
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'name' => $name,
        'base_price' => $basePrice,
        'now' => $now,
    ]);

    return (int) $stmt->fetchColumn();
}

function ensure_price_list(PDO $pdo, int $tenantId): int {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare(
        "insert into price_lists (tenant_id, name, code, description, is_default, is_active, sort_order, created_at, updated_at)
         values (:tenant_id, 'Lista Smoke', 'SMOKE', 'Lista temporal para prueba de sincronizacion.', false, true, 998, :now, :now)
         on conflict (tenant_id, code) do update set name = excluded.name, is_active = true, updated_at = excluded.updated_at
         returning id"
    );
    $stmt->execute(['tenant_id' => $tenantId, 'now' => $now]);

    return (int) $stmt->fetchColumn();
}

function set_product_price(PDO $pdo, int $tenantId, int $productId, int $priceListId, float $price): void {
    $now = date('Y-m-d H:i:s');
    exec_sql(
        $pdo,
        "insert into product_prices (tenant_id, product_id, price_list_id, price, currency, is_active, created_at, updated_at)
         values (:tenant_id, :product_id, :price_list_id, :price, 'USD', true, :now, :now)
         on conflict (tenant_id, product_id, price_list_id) do update set
            price = excluded.price,
            currency = excluded.currency,
            is_active = true,
            updated_at = excluded.updated_at",
        [
            'tenant_id' => $tenantId,
            'product_id' => $productId,
            'price_list_id' => $priceListId,
            'price' => $price,
            'now' => $now,
        ]
    );
}

function insert_outbox(PDO $pdo, int $tenantId, string $eventUuid, string $eventType, string $aggregateType, ?int $aggregateId, array $payload): void {
    $now = date('Y-m-d H:i:s');
    exec_sql(
        $pdo,
        "insert into sync_outbox
            (tenant_id, event_uuid, origin_node_id, target_node_id, target_scope, event_type, aggregate_type, aggregate_id, aggregate_uuid, payload, occurred_at, available_at, status, attempts, created_at, updated_at)
         values
            (:tenant_id, :event_uuid, null, null, 'tenant', :event_type, :aggregate_type, :aggregate_id, null, :payload, :now, :now, 'pending', 0, :now, :now)
         on conflict (tenant_id, event_uuid) do nothing",
        [
            'tenant_id' => $tenantId,
            'event_uuid' => $eventUuid,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => json_encode($payload),
            'now' => $now,
        ]
    );
}

$tenantSlug = getenv('SMOKE_TENANT_SLUG') ?: 'demo-caracas';
$local = pdo_from_env('SMOKE_LOCAL');
$cloud = pdo_from_env('SMOKE_CLOUD');

$localTenantId = ensure_tenant($local, $tenantSlug);
$cloudTenantId = ensure_tenant($cloud, $tenantSlug);
$cloudUserId = ensure_user($cloud, $cloudTenantId);
$cloudToken = issue_token($cloud, $cloudTenantId, $cloudUserId);

$localProductId = ensure_product($local, $localTenantId, 'Producto Smoke Local', 10.00);
$cloudProductId = ensure_product($cloud, $cloudTenantId, 'Producto Smoke Nube', 10.00);
$localListId = ensure_price_list($local, $localTenantId);
$cloudListId = ensure_price_list($cloud, $cloudTenantId);

set_product_price($local, $localTenantId, $localProductId, $localListId, 10.00);
set_product_price($cloud, $cloudTenantId, $cloudProductId, $cloudListId, 77.77);

$localEventUuid = uuid_v4();
$cloudEventUuid = uuid_v4();

insert_outbox($local, $localTenantId, $localEventUuid, 'product.updated', 'product', $localProductId, [
    'sku' => 'SYNC-SMOKE-001',
    'name' => 'Producto Smoke Local Subido',
    'tracking_type' => 'quantity',
    'base_price' => 45.67,
    'sale_currency' => 'USD',
    'is_active' => true,
]);

insert_outbox($cloud, $cloudTenantId, $cloudEventUuid, 'product_price.updated', 'product_price', $cloudProductId, [
    'sku' => 'SYNC-SMOKE-001',
    'price_list_code' => 'SMOKE',
    'price' => 77.77,
    'currency' => 'USD',
    'is_active' => true,
]);

echo json_encode([
    'tenant_slug' => $tenantSlug,
    'cloud_token' => $cloudToken,
    'local_event_uuid' => $localEventUuid,
    'cloud_event_uuid' => $cloudEventUuid,
    'expected_price' => 77.77,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
'@

$fixtureJson = Invoke-PhpTemp "prepare-fixture.php" $fixture
$fixtureData = ($fixtureJson -join "`n") | ConvertFrom-Json
$CloudToken = [string] $fixtureData.cloud_token

Invoke-CloudArtisan @("config:clear")

$cmdFile = Join-Path $TempDir "cloud-api.cmd"
$apiProcess = $null

if ($UseExternalCloudApi) {
    Write-Step "Usando API nube externa en $CloudApiUrl"
} else {
    Write-Step "Levantando API nube temporal en $CloudApiUrl"

    if (Test-PortOpen $CloudApiPort) {
        Fail "El puerto $CloudApiPort ya esta ocupado. Cierra ese proceso o ejecuta el script con -CloudApiPort otro_puerto."
    }

    $cmd = @"
@echo off
cd /d "$RepoRoot"
set "APP_ENV=cloudtest"
set "DB_CONNECTION=pgsql"
set "DB_HOST=$CloudDbHost"
set "DB_PORT=$CloudDbPort"
set "DB_DATABASE=$CloudDbName"
set "DB_USERNAME=$CloudDbUser"
set "DB_PASSWORD=$CloudDbPassword"
"$PhpPath" artisan serve --host=127.0.0.1 --port=$CloudApiPort
"@
    Set-Content -LiteralPath $cmdFile -Value $cmd -Encoding ASCII

    $apiProcess = Start-Process -FilePath "cmd.exe" -ArgumentList "/c `"$cmdFile`"" -WindowStyle Hidden -PassThru
}

try {
    if (!$UseExternalCloudApi -and !(Wait-Port $CloudApiPort 25)) {
        Fail "No se pudo levantar la API nube temporal en el puerto $CloudApiPort."
    }

    Write-Step "Ejecutando worker local contra la API nube"
    Push-Location $RepoRoot
    try {
        & $PhpPath "artisan" "sync:run" $TenantSlug "--node=$NodeCode" "--name=Prueba smoke local" "--cloud-url=$CloudApiUrl" "--token=$CloudToken" "--limit=20"
        if ($LASTEXITCODE -ne 0) {
            Fail "El worker sync:run fallo."
        }
    } finally {
        Pop-Location
    }

    Write-Step "Validando resultados en local y nube"
    $env:SMOKE_LOCAL_EVENT_UUID = [string] $fixtureData.local_event_uuid
    $env:SMOKE_CLOUD_EVENT_UUID = [string] $fixtureData.cloud_event_uuid
    $env:SMOKE_EXPECTED_PRICE = [string] $fixtureData.expected_price

    $verify = @'
<?php
function pdo_from_env(string $prefix): PDO {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        getenv($prefix.'_HOST'),
        getenv($prefix.'_PORT'),
        getenv($prefix.'_DB')
    );

    return new PDO($dsn, getenv($prefix.'_USER'), getenv($prefix.'_PASSWORD'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function scalar(PDO $pdo, string $sql, array $params = []) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

$tenantSlug = getenv('SMOKE_TENANT_SLUG') ?: 'demo-caracas';
$localEventUuid = getenv('SMOKE_LOCAL_EVENT_UUID');
$cloudEventUuid = getenv('SMOKE_CLOUD_EVENT_UUID');
$expectedPrice = (float) getenv('SMOKE_EXPECTED_PRICE');
$local = pdo_from_env('SMOKE_LOCAL');
$cloud = pdo_from_env('SMOKE_CLOUD');

$localTenantId = (int) scalar($local, 'select id from tenants where slug = ?', [$tenantSlug]);
$cloudTenantId = (int) scalar($cloud, 'select id from tenants where slug = ?', [$tenantSlug]);

$localPrice = (float) scalar(
    $local,
    "select pp.price
     from product_prices pp
     join products p on p.tenant_id = pp.tenant_id and p.id = pp.product_id
     join price_lists pl on pl.tenant_id = pp.tenant_id and pl.id = pp.price_list_id
     where pp.tenant_id = ? and p.sku = 'SYNC-SMOKE-001' and pl.code = 'SMOKE'",
    [$localTenantId]
);
$localOutboxStatus = scalar($local, 'select status from sync_outbox where tenant_id = ? and event_uuid = ?', [$localTenantId, $localEventUuid]);
$cloudInboxStatus = scalar($cloud, 'select status from sync_inbox where tenant_id = ? and event_uuid = ?', [$cloudTenantId, $localEventUuid]);
$cloudOutboxStatus = scalar($cloud, 'select status from sync_outbox where tenant_id = ? and event_uuid = ?', [$cloudTenantId, $cloudEventUuid]);
$localInboxStatus = scalar($local, 'select status from sync_inbox where tenant_id = ? and event_uuid = ?', [$localTenantId, $cloudEventUuid]);

$ok = abs($localPrice - $expectedPrice) < 0.0001
    && $localOutboxStatus === 'processed'
    && $cloudInboxStatus === 'received'
    && $cloudOutboxStatus === 'processed'
    && $localInboxStatus === 'applied';

echo json_encode([
    'ok' => $ok,
    'precio_local_smoke' => $localPrice,
    'precio_esperado' => $expectedPrice,
    'outbox_local' => $localOutboxStatus,
    'inbox_nube' => $cloudInboxStatus,
    'outbox_nube' => $cloudOutboxStatus,
    'inbox_local' => $localInboxStatus,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

if (!$ok) {
    exit(1);
}
'@

    $verifyJson = Invoke-PhpTemp "verify-result.php" $verify
    Write-Host ($verifyJson -join "`n")
    Write-Host ""
    Write-Host "PRUEBA COMPLETADA: sincronizacion local <-> nube validada." -ForegroundColor Green
} finally {
    if (!$KeepCloudApi -and $apiProcess -and !$apiProcess.HasExited) {
        Stop-Process -Id $apiProcess.Id -Force
    }

    if (!$KeepCloudApi -and !$UseExternalCloudApi) {
        Stop-PortOwner $CloudApiPort
    }

    if (!$KeepCloudApi -and (Test-Path -LiteralPath $cmdFile)) {
        Remove-Item -LiteralPath $cmdFile -Force
    }
}
