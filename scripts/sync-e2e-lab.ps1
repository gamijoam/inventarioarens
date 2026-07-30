param(
    [Parameter(Mandatory = $true)]
    [string]$PairingCodeNodeA,
    [Parameter(Mandatory = $true)]
    [string]$PairingCodeNodeB,
    [string]$CloudApiUrl = 'https://app.miinventariofacil.com/api',
    [int]$Rounds = 6,
    [string]$Marker = (Get-Date -Format 'yyyyMMdd-HHmmss'),
    [switch]$KeepArtifacts
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$php = (Get-Command php -ErrorAction Stop).Source
$labRoot = Join-Path $repoRoot 'storage\app\sync-e2e-lab'
$completed = $false

if ($Marker -notmatch '^[A-Za-z0-9-]{4,24}$') {
    throw 'La marca de corrida debe tener entre 4 y 24 letras, numeros o guiones.'
}

$runRoot = Join-Path $labRoot $Marker

if ($CloudApiUrl -notmatch '^https://') {
    throw 'La prueba E2E solo acepta una API HTTPS.'
}

if ($PairingCodeNodeA -eq $PairingCodeNodeB) {
    throw 'Usa dos codigos de vinculacion distintos. Cada codigo se consume una sola vez.'
}

New-Item -ItemType Directory -Force -Path $runRoot | Out-Null

function Invoke-LabApi([string]$Code, [string]$NodeCode, [string]$NodeName) {
    $response = Invoke-RestMethod -Method Post -Uri "$CloudApiUrl/sync/pairing-codes/redeem" -ContentType 'application/json' -Body (@{
        code = $Code
        node_code = $NodeCode
        node_name = $NodeName
    } | ConvertTo-Json)

    if (-not $response.data.token -or -not $response.data.tenant.slug) {
        throw 'La nube no devolvio un token y empresa validos para el nodo de laboratorio.'
    }

    return $response.data
}

function Invoke-LabArtisan([string]$Database, [string[]]$Arguments) {
    $previousConnection = $env:DB_CONNECTION
    $previousDatabase = $env:DB_DATABASE
    $previousPassword = $env:SYNC_BOOTSTRAP_PASSWORD
    $env:DB_CONNECTION = 'sqlite'
    $env:DB_DATABASE = $Database
    $env:SYNC_BOOTSTRAP_PASSWORD = 'sync-lab-local-only-password'

    try {
        & $php artisan @Arguments
        if ($LASTEXITCODE -ne 0) {
            $safeArguments = $Arguments | ForEach-Object {
                if ($_ -like '--token=*') {
                    return '--token=[redactado]'
                }

                return $_
            }

            throw "Fallo artisan $($safeArguments -join ' ') (codigo $LASTEXITCODE)."
        }
    } finally {
        $env:DB_CONNECTION = $previousConnection
        $env:DB_DATABASE = $previousDatabase
        $env:SYNC_BOOTSTRAP_PASSWORD = $previousPassword
    }
}

function Initialize-LabNode([string]$Name, $Pairing, [string]$Database) {
    Write-Host "Preparando nodo $Name..."
    New-Item -ItemType File -Force -Path $Database | Out-Null
    Invoke-LabArtisan $Database @('migrate', '--force', '--no-interaction')
    Invoke-LabArtisan $Database @(
        'sync:prepare-local',
        $Pairing.tenant.slug,
        $Pairing.tenant.name,
        "sync-lab-$Name@$($Pairing.tenant.slug).local",
        ('--user-name=Sync Lab '+$Name),
        '--role=Administrador local'
    )
}

try {
    $nodeSuffix = [Guid]::NewGuid().ToString('N').Substring(0, 8).ToUpperInvariant()
    $nodeACode = "E2E-A-$nodeSuffix"
    $nodeBCode = "E2E-B-$nodeSuffix"

    Write-Host 'Vinculando los dos nodos de laboratorio...'
    $pairingA = Invoke-LabApi $PairingCodeNodeA $nodeACode 'E2E Sync node A'
    $pairingB = Invoke-LabApi $PairingCodeNodeB $nodeBCode 'E2E Sync node B'

    if ($pairingA.tenant.slug -ne $pairingB.tenant.slug) {
        throw 'Los dos codigos deben pertenecer a la misma empresa de laboratorio.'
    }

    $tenant = [string]$pairingA.tenant.slug
    $nodeADatabase = Join-Path $runRoot 'node-a.sqlite'
    $nodeBDatabase = Join-Path $runRoot 'node-b.sqlite'
    Initialize-LabNode 'a' $pairingA $nodeADatabase
    Initialize-LabNode 'b' $pairingB $nodeBDatabase

    $syncA = @('sync:run', $tenant, "--node=$nodeACode", '--name=E2E Sync node A', "--installation=E2E-$nodeSuffix", "--cloud-url=$CloudApiUrl", "--token=$($pairingA.token)", '--limit=100')
    $syncB = @('sync:run', $tenant, "--node=$nodeBCode", '--name=E2E Sync node B', "--installation=E2E-$nodeSuffix", "--cloud-url=$CloudApiUrl", "--token=$($pairingB.token)", '--limit=100')

    Write-Host 'Descargando la foto inicial en ambos nodos...'
    for ($round = 1; $round -le $Rounds; $round++) {
        Invoke-LabArtisan $nodeADatabase $syncA
        Invoke-LabArtisan $nodeBDatabase $syncB
    }

    Write-Host 'Preparando el catalogo minimo POS/CxC en ambos nodos...'
    Invoke-LabArtisan $nodeADatabase @('sync:lab:prepare-pos-credit', $tenant, $Marker)
    Invoke-LabArtisan $nodeBDatabase @('sync:lab:prepare-pos-credit', $tenant, $Marker)

    Write-Host 'Emitiendo un cambio local en el nodo A...'
    Invoke-LabArtisan $nodeADatabase @('sync:lab:emit-customer', $tenant, $Marker)
    Invoke-LabArtisan $nodeADatabase $syncA

    Write-Host 'Simulando nodo B desconectado antes de recuperarlo...'
    Start-Sleep -Seconds 2

    Write-Host 'Recuperando nodo B y aplicando eventos pendientes...'
    for ($round = 1; $round -le 3; $round++) {
        Invoke-LabArtisan $nodeBDatabase $syncB
    }
    Invoke-LabArtisan $nodeBDatabase @('sync:lab:verify-customer', $tenant, $Marker, '--require-inbox')

    Write-Host 'Emitiendo venta POS a credito desde el nodo A...'
    Invoke-LabArtisan $nodeADatabase @('sync:lab:emit-pos-credit', $tenant, $Marker, 'sale')
    Invoke-LabArtisan $nodeADatabase $syncA

    Write-Host 'Registrando cobro posterior CxC mientras el nodo B esta desconectado...'
    Invoke-LabArtisan $nodeADatabase @('sync:lab:emit-pos-credit', $tenant, $Marker, 'collection')
    Invoke-LabArtisan $nodeADatabase $syncA

    Write-Host 'Recuperando en B la venta, stock y cobranza...'
    for ($round = 1; $round -le 3; $round++) {
        Invoke-LabArtisan $nodeBDatabase $syncB
    }
    Invoke-LabArtisan $nodeBDatabase @('sync:lab:verify-pos-credit', $tenant, $Marker)

    Write-Host 'Repitiendo el ciclo financiero para comprobar idempotencia...'
    Invoke-LabArtisan $nodeBDatabase $syncB
    Invoke-LabArtisan $nodeBDatabase @('sync:lab:verify-pos-credit', $tenant, $Marker)

    Write-Host 'Repitiendo el ciclo del nodo B para comprobar idempotencia...'
    Invoke-LabArtisan $nodeBDatabase $syncB
    Invoke-LabArtisan $nodeBDatabase @('sync:lab:verify-customer', $tenant, $Marker, '--require-inbox')

    Write-Host ''
    Write-Host 'Prueba E2E de sincronizacion completada.' -ForegroundColor Green
    Write-Host "Empresa: $tenant"
    Write-Host "Artefactos: $runRoot"
    Write-Host 'Resultado: cliente, venta POS, stock y CxC viajaron A -> nube -> B sin duplicados.'
    $completed = $true
} finally {
    if ($completed -and -not $KeepArtifacts -and (Test-Path -LiteralPath $runRoot)) {
        Remove-Item -LiteralPath $runRoot -Recurse -Force
    }

    if (-not $completed -and (Test-Path -LiteralPath $runRoot)) {
        Write-Host "La prueba fallo. Conserva los artefactos para diagnostico: $runRoot" -ForegroundColor Yellow
    }
}
