param(
    [Parameter(Mandatory = $true)]
    [string]$ConnectorRoot
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$taskName = 'InventarioArens Print Connector'
$connectorExe = Join-Path $ConnectorRoot 'InventarioArens-Print-Connector.exe'
$action = New-ScheduledTaskAction -Execute $connectorExe -Argument 'run' -WorkingDirectory $ConnectorRoot
$trigger = New-ScheduledTaskTrigger -AtStartup
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Principal $principal -Force | Out-Null
Start-ScheduledTask -TaskName $taskName
Write-Output "Tarea instalada: $taskName"
