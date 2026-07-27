param(
    [Parameter(Mandatory = $true)]
    [string] $AppRoot
)

Get-CimInstance Win32_Process |
    Where-Object {
        $_.Name -eq "php.exe" -and $_.CommandLine -like "*$AppRoot*"
    } |
    ForEach-Object {
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }
