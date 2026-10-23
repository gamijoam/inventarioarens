param(
    [Parameter(Mandatory = $true)] [string]$RuntimeDirectory,
    [Parameter(Mandatory = $true)] [string]$DataRoot,
    [string[]]$LegacyConfigPaths = @()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Test-PhpDirective([string]$Content, [string]$Name) {
    return [regex]::IsMatch($Content, '(?im)^\s*' + [regex]::Escape($Name) + '\s*=')
}

function Test-PhpExtension([string]$Content, [string]$Name) {
    foreach ($match in [regex]::Matches($Content, '(?im)^\s*extension\s*=\s*([^;#\r\n]+)')) {
        $value = $match.Groups[1].Value.Trim().Trim('"').Trim("'")
        $extensionName = [IO.Path]::GetFileNameWithoutExtension($value)
        $extensionName = $extensionName -replace '^php_', ''
        if ($extensionName -ieq $Name) { return $true }
    }
    return $false
}

$RuntimeDirectory = [IO.Path]::GetFullPath($RuntimeDirectory)
$RuntimeExtensionDirectory = Join-Path $RuntimeDirectory 'ext'
$DataRoot = [IO.Path]::GetFullPath($DataRoot)
$TargetIni = Join-Path $RuntimeDirectory 'php.ini'
$DefaultContent = @"
extension_dir = "$RuntimeExtensionDirectory"
extension=pdo_sqlite
extension=sqlite3
extension=mbstring
extension=fileinfo
extension=openssl
extension=curl
extension=zip
extension=gd
extension=intl
extension=sodium
opcache.enable=1
opcache.enable_cli=1
memory_limit=512M
"@

$SourceIni = $null
foreach ($candidate in $LegacyConfigPaths) {
    if ($candidate -and (Test-Path -LiteralPath $candidate -PathType Leaf)) {
        $SourceIni = [IO.Path]::GetFullPath($candidate)
        break
    }
}

if ($SourceIni) {
    # Fail before switching services if a previous custom configuration cannot be read or backed up.
    $Content = [IO.File]::ReadAllText($SourceIni)
    $SourceRuntimeDirectory = Split-Path -Parent $SourceIni
    $Content = [regex]::Replace(
        $Content,
        [regex]::Escape($SourceRuntimeDirectory),
        [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $RuntimeDirectory },
        [System.Text.RegularExpressions.RegexOptions]::IgnoreCase
    )
    $BackupDirectory = Join-Path $DataRoot (Join-Path 'backups\frankenphp-config' ((Get-Date).ToString('yyyyMMdd-HHmmss-fff') + '-' + [guid]::NewGuid().ToString('N')))
    New-Item -ItemType Directory -Path $BackupDirectory -Force | Out-Null
    Copy-Item -LiteralPath $SourceIni -Destination (Join-Path $BackupDirectory 'php.ini')
} else {
    $Content = $DefaultContent
}

$extensionDirectoryLine = 'extension_dir = "' + $RuntimeExtensionDirectory + '"'
$extensionDirectoryPattern = '(?im)^\s*extension_dir\s*=.*$'
if ([regex]::IsMatch($Content, $extensionDirectoryPattern)) {
    $Content = [regex]::Replace($Content, $extensionDirectoryPattern, [System.Text.RegularExpressions.MatchEvaluator]{ param($match) $extensionDirectoryLine })
} else {
    $Content = $Content.TrimEnd() + [Environment]::NewLine + $extensionDirectoryLine
}

$missingLines = [Collections.Generic.List[string]]::new()
foreach ($extension in @('pdo_sqlite', 'sqlite3', 'mbstring', 'fileinfo', 'openssl', 'curl', 'zip', 'gd', 'intl', 'sodium')) {
    if (-not (Test-PhpExtension $Content $extension)) { $missingLines.Add("extension=$extension") }
}
foreach ($directive in @(
    @{ name = 'opcache.enable'; value = '1' },
    @{ name = 'opcache.enable_cli'; value = '1' },
    @{ name = 'memory_limit'; value = '512M' }
)) {
    if (-not (Test-PhpDirective $Content $directive.name)) {
        $missingLines.Add("$($directive.name)=$($directive.value)")
    }
}
if ($missingLines.Count -gt 0) {
    $Content = $Content.TrimEnd() + [Environment]::NewLine + ($missingLines -join [Environment]::NewLine) + [Environment]::NewLine
}

New-Item -ItemType Directory -Path $RuntimeDirectory -Force | Out-Null
[IO.File]::WriteAllText($TargetIni, $Content, [Text.UTF8Encoding]::new($false))
Write-Host "Configuración FrankenPHP lista: $TargetIni"
if ($SourceIni) { Write-Host "Configuración previa respaldada y migrada desde: $SourceIni" }
