param(
    [ValidateSet('local', 'vps')]
    [string] $Target = 'local',
    [string] $PhpPath = "C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe",
    [string] $Prefix = 'labday',
    [string] $Password = 'labday-password-2026',
    [int] $Tenants = 3,
    [int] $Products = 10,
    [int] $Sales = 10,
    [int] $K6Vus = 6,
    [string] $LocalApi = 'http://127.0.0.1:8000/api',
    [string] $VpsApi = 'https://app.miinventariofacil.com/api',
    [switch] $SkipK6,
    [switch] $SkipPlaywright,
    [switch] $AllowProduction,
    [string] $PlaywrightProject = 'ui'
)

$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot
$baseUrl = if ($Target -eq 'vps') { $VpsApi } else { $LocalApi }

if ($Target -eq 'vps' -and -not $AllowProduction) {
    throw 'El destino es la nube. Usa -AllowProduction solo durante una ventana aprobada y con prefijo desechable.'
}

if ($baseUrl -match 'app\.miinventariofacil\.com' -and $Target -eq 'local' -and -not $AllowProduction) {
    throw 'LocalLab no debe apuntar a la nube sin -AllowProduction.'
}

function Write-Step([string] $Message) {
    Write-Host ''
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Invoke-Artisan([string[]] $Arguments) {
    if ($Target -eq 'vps') {
        throw 'El lab:day sobre VPS se ejecuta con SSH en el propio servidor. Usa scripts/ssh_run.py o ejecuta el comando manualmente.'
    }
    & $PhpPath @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Fallo el comando artisan: $($Arguments -join ' ')"
    }
}

Write-Step "Dia simulado: target=$Target api=$baseUrl prefix=$Prefix tenants=$Tenants sales=$Sales"

Write-Step "Fase 1/3 - Preparar datos de laboratorio (rol Gerente, 2 almacenes, proveedor)"
Invoke-Artisan @('artisan', 'lab:day',
    '--tenants', "$Tenants",
    '--products', "$Products",
    '--password', $Password,
    '--prefix', $Prefix,
    '--sales', "$Sales",
    '--base-url', $baseUrl,
    '--force')

Write-Step "Fase 2/3 - Carga k6 (ventas POS concurrentes + colision de stock)"
if (-not $SkipK6) {
    $stressArgs = @(
        '-Scenario', 'pos',
        '-Password', $Password,
        '-BaseUrl', $baseUrl,
        '-Prefix', $Prefix,
        '-Tenants', "$Tenants",
        '-Vus', "$K6Vus",
        '-Iterations', "$Sales",
        '-Products', "$Products"
    )
    if ($AllowProduction) { $stressArgs += '-AllowProduction' }
    & (Join-Path $RepoRoot 'scripts\run-stress-lab.ps1') @stressArgs
    if ($LASTEXITCODE -ne 0) {
        throw 'El laboratorio k6 POS fallo (latencia, errores o idempotencia).'
    }
} else {
    Write-Host '  (k6 omitido)'
}

Write-Step "Fase 3/3 - Playwright E2E (UI del POS)"
if (-not $SkipPlaywright -and $Target -eq 'local') {
    Push-Location (Join-Path $RepoRoot 'frontend')
    try {
        $env:PLAYWRIGHT_BASE_URL = $LocalApi -replace '/api$', ''
        $env:PLAYWRIGHT_FRONTEND_URL = 'http://127.0.0.1:5173'
        & pnpm e2e --project=$PlaywrightProject
        if ($LASTEXITCODE -ne 0) {
            throw 'Playwright E2E fallo.'
        }
    } finally {
        Pop-Location
    }
} elseif ($Target -eq 'vps') {
    Write-Host '  (Playwright sobre VPS: usa un runner local apuntando al VPS, ver docs/LAB_DAY_SIMULADO.md)'
} else {
    Write-Host '  (Playwright omitido)'
}

Write-Step "Dia simulado completado"
Write-Host 'Reporte del lab: ver storage/app/lab-reports/<fecha>/ en el destino y storage/app/sync-e2e-lab/ si aplica.' -ForegroundColor Green
