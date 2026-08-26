param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$taskName = 'InventarioArens Print Connector'
Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
Write-Output "Tarea eliminada: $taskName"
