param(
    [Parameter(Mandatory = $true)]
    [string]$Password,
    [string]$BaseUrl = 'http://127.0.0.1:8000/api',
    [string]$Prefix = 'loadtest',
    [int]$Tenants = 3,
    [int]$Vus = 9,
    [string]$Duration = '1m',
    [ValidateSet('read', 'pos', 'race')]
    [string]$Scenario = 'read',
    [int]$Products = 100,
    [int]$Iterations = 5,
    [int]$PosP95Ms = 3000,
    [ValidateSet('quantity', 'serialized')]
    [string]$RaceTarget = 'quantity',
    [int]$RaceTenant = 1,
    [int]$RaceP95Ms = 5000,
    [switch]$AllowProduction
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot

if ($BaseUrl -match 'app\.miinventariofacil\.com' -and -not $AllowProduction) {
    throw 'El destino es produccion. Usa -AllowProduction solo durante una ventana aprobada.'
}

$environment = @(
    '-e', "BASE_URL=$BaseUrl",
    '-e', "STRESS_PASSWORD=$Password",
    '-e', "STRESS_PREFIX=$Prefix",
    '-e', "STRESS_TENANTS=$Tenants",
    '-e', "STRESS_VUS=$Vus",
    '-e', "STRESS_DURATION=$Duration",
    '-e', "STRESS_PRODUCTS=$Products",
    '-e', "STRESS_POS_VUS=$Vus",
    '-e', "STRESS_POS_ITERATIONS=$Iterations",
    '-e', "STRESS_POS_P95_MS=$PosP95Ms",
    '-e', "STRESS_RACE_VUS=$Vus",
    '-e', "STRESS_RACE_TARGET=$RaceTarget",
    '-e', "STRESS_RACE_TENANT=$RaceTenant",
    '-e', "STRESS_RACE_P95_MS=$RaceP95Ms"
)

if ($AllowProduction) {
    $environment += @('-e', 'STRESS_ALLOW_PRODUCTION=yes')
}

if ($Scenario -eq 'pos') {
    $script = 'stress/k6/pos-cash-inventory.js'
    Write-Host "Laboratorio POS: $Tenants empresas, $Vus cajeros virtuales, $Iterations ventas por cajero."
} elseif ($Scenario -eq 'race') {
    $script = 'stress/k6/pos-stock-race.js'
    Write-Host "Colision POS: $Vus cajeros intentan vender una sola unidad $RaceTarget en ${Prefix}-$RaceTenant."
} else {
    $script = 'stress/k6/three-tenants-web.js'
    Write-Host "Laboratorio de lectura: $Tenants empresas, $Vus usuarios virtuales, $Duration."
}

$localK6 = Get-Command k6 -ErrorAction SilentlyContinue
if ($localK6) {
    $savedEnvironment = @{}
    foreach ($entry in $environment) {
        if ($entry -like '*=*' -and $entry -notlike '-e') {
            $key, $value = $entry -split '=', 2
            $savedEnvironment[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
            [Environment]::SetEnvironmentVariable($key, $value, 'Process')
        }
    }

    try {
        & $localK6.Source run (Join-Path $repoRoot $script)
        exit $LASTEXITCODE
    } finally {
        foreach ($key in $savedEnvironment.Keys) {
            [Environment]::SetEnvironmentVariable($key, $savedEnvironment[$key], 'Process')
        }
    }
}

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Instala k6 en este equipo o Docker Desktop para ejecutar el laboratorio.'
}

& docker run --rm -i -v "${repoRoot}:/work" -w /work @environment grafana/k6 run $script
exit $LASTEXITCODE
