param(
    [string]$Version = '0.1.0',
    [switch]$StageOnly
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$RepoRoot = Split-Path -Parent $PSScriptRoot

function Resolve-InnoSetupCompiler {
    $command = Get-Command iscc.exe -ErrorAction SilentlyContinue
    if ($command) { return $command.Source }
    foreach ($candidate in @(
        (Join-Path ${env:ProgramFiles(x86)} 'Inno Setup 6\ISCC.exe'),
        (Join-Path $env:ProgramFiles 'Inno Setup 6\ISCC.exe')
    )) {
        if ($candidate -and (Test-Path -LiteralPath $candidate)) { return $candidate }
    }
    return $null
}

Push-Location $RepoRoot
try {
    & node scripts/prepare-portable-php.cjs
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar PHP portable.' }
    & node scripts/prepare-winsw.cjs
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar WinSW.' }
    & node scripts/stage-local-motor.cjs
    if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar el payload del Motor Local.' }

    if ($StageOnly) { return }
    $iscc = Resolve-InnoSetupCompiler
    if (-not $iscc) { throw 'Inno Setup 6 no esta instalado. Use -StageOnly o instale Inno Setup.' }
    & $iscc "/DMotorVersion=$Version" 'installer\windows\MotorLocal.iss'
    if ($LASTEXITCODE -ne 0) { throw "Inno Setup fallo con codigo $LASTEXITCODE." }
} finally {
    Pop-Location
}
