param(
    [string] $PhpRuntime = "build\windows-runtime\php",
    [string] $OutputDirectory = "build\windows-installer"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$RepoRoot = Split-Path -Parent $PSScriptRoot
$RuntimeSource = Join-Path $RepoRoot $PhpRuntime
$OutputRoot = Join-Path $RepoRoot $OutputDirectory
$Stage = Join-Path $OutputRoot "stage"
$PhpExe = Join-Path $RuntimeSource "php.exe"

function Remove-DirectoryRobust {
    param([Parameter(Mandatory = $true)][string] $Path)

    if (!(Test-Path -LiteralPath $Path)) {
        return
    }

    $empty = Join-Path ([System.IO.Path]::GetTempPath()) ("inventoryarens-empty-" + [guid]::NewGuid().ToString("N"))
    New-Item -ItemType Directory -Force -Path $empty | Out-Null
    try {
        & robocopy.exe $empty $Path /MIR /NFL /NDL /NJH /NJS /NP | Out-Null
        if ($LASTEXITCODE -gt 7) { throw "Robocopy cleanup fallo con codigo $LASTEXITCODE." }
        Remove-Item -LiteralPath $Path -Recurse -Force -ErrorAction Stop
    } finally {
        Remove-Item -LiteralPath $empty -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Resolve-InnoSetupCompiler {
    $command = Get-Command iscc.exe -ErrorAction SilentlyContinue
    if ($null -ne $command) {
        return $command.Source
    }

    $candidates = @(
        (Join-Path ${env:ProgramFiles(x86)} "Inno Setup 6\ISCC.exe"),
        (Join-Path $env:ProgramFiles "Inno Setup 6\ISCC.exe"),
        (Join-Path ${env:ProgramFiles(x86)} "Inno Setup 5\ISCC.exe"),
        (Join-Path $env:ProgramFiles "Inno Setup 5\ISCC.exe")
    )

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) {
            return $candidate
        }
    }

    return $null
}

if (!(Test-Path -LiteralPath $PhpExe)) {
    throw "PHP portable no encontrado en $PhpExe. Descarga PHP 8.3/8.4 NTS x64 y extraelo alli."
}

Push-Location $RepoRoot
try {
    $pnpm = Get-Command pnpm.cmd -ErrorAction SilentlyContinue
    if ($null -eq $pnpm) {
        throw "pnpm no esta instalado. Instala pnpm para compilar frontend/dist antes de crear el instalador."
    }
    $env:VITE_API_BASE_URL = "http://127.0.0.1:8787/api"
    & $pnpm.Source --dir frontend run build
    if ($LASTEXITCODE -ne 0) { throw "La compilacion del frontend fallo con codigo $LASTEXITCODE." }

    if (Test-Path -LiteralPath $OutputRoot) {
        Remove-DirectoryRobust -Path $OutputRoot
    }
    New-Item -ItemType Directory -Force -Path $Stage | Out-Null

    $excludeDirectories = @(
        ".git",
        ".github",
        ".agents",
        ".harness",
        ".codex",
        ".githooks",
        ".opencode",
        ".pnpm-store",
        ".tools",
        "build",
        "docs",
        "DOCUMENTOS",
        "dist",
        "graphify-out",
        "node_modules",
        "frontend\node_modules",
        "systemd",
        "tests",
        "tools",
        "storage\logs",
        "storage\framework\cache",
        "storage\framework\sessions",
        "storage\framework\testing",
        "storage\framework\views"
    )
    $excludeFiles = @(
        ".env",
        ".env.*.bak.*",
        "database\database.sqlite",
        "Dockerfile",
        "docker-compose.yml",
        "phpunit.xml",
        "phpunit.sqlite.xml",
        ".phpunit.result.cache",
        "reset-test-db.php",
        "ssh_query.sql",
        "storage\app\sync-worker\sync-config.json"
    )
    $robocopyArgs = @($RepoRoot, $Stage, "/E", "/NFL", "/NDL", "/NJH", "/NJS", "/NP")
    foreach ($directory in $excludeDirectories) { $robocopyArgs += "/XD"; $robocopyArgs += (Join-Path $RepoRoot $directory) }
    foreach ($file in $excludeFiles) {
        $robocopyArgs += "/XF"
        if ($file.Contains("*")) {
            $robocopyArgs += $file
        } else {
            $robocopyArgs += (Join-Path $RepoRoot $file)
        }
    }
    & robocopy.exe @robocopyArgs
    if ($LASTEXITCODE -gt 7) { throw "Robocopy fallo con codigo $LASTEXITCODE." }

    New-Item -ItemType Directory -Force -Path (Join-Path $Stage "runtime\php") | Out-Null
    Copy-Item -Path (Join-Path $RuntimeSource "*") -Destination (Join-Path $Stage "runtime\php") -Recurse -Force

    New-Item -ItemType Directory -Force -Path (Join-Path $Stage "installer\windows") | Out-Null
    Copy-Item -Path (Join-Path $RepoRoot "installer\windows\*") -Destination (Join-Path $Stage "installer\windows") -Recurse -Force
    Copy-Item -Path (Join-Path $RepoRoot "installer\windows\InventarioArens.iss") -Destination $OutputRoot -Force

    $iscc = Resolve-InnoSetupCompiler
    if ($null -eq $iscc) {
        Write-Host "Staging listo en $Stage. iscc.exe no esta instalado; se omite compilacion." -ForegroundColor Yellow
    } else {
        & $iscc (Join-Path $OutputRoot "InventarioArens.iss")
        if ($LASTEXITCODE -ne 0) { throw "Inno Setup fallo con codigo $LASTEXITCODE." }
    }
} finally {
    Pop-Location
}
