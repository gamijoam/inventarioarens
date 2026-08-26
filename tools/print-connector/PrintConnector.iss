#ifndef ConnectorVersion
  #define ConnectorVersion "0.1.0"
#endif
#define ConnectorName "Inventario Arens Print Connector"
#define StageRoot "..\..\build\print-connector\stage"

[Setup]
AppId={{8B37F0EF-2C2E-4A37-9C02-A6E7B0B6A3D8}
AppName={#ConnectorName}
AppVersion={#ConnectorVersion}
AppPublisher=Inventario Arens
DefaultDirName={autopf}\Inventario Arens\Print Connector
DefaultGroupName=Inventario Arens
OutputDir=..\..\build\print-connector\release
OutputBaseFilename=InventarioArens-Print-Connector-Setup-{#ConnectorVersion}
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DisableProgramGroupPage=yes
WizardStyle=modern
UninstallDisplayName={#ConnectorName}

[Files]
Source: "{#StageRoot}\InventarioArens-Print-Connector.exe"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#StageRoot}\install-task.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#StageRoot}\uninstall-task.ps1"; DestDir: "{app}"; Flags: ignoreversion
Source: "{#StageRoot}\VERSION.txt"; DestDir: "{app}"; Flags: ignoreversion

[Dirs]
Name: "{commonappdata}\InventarioArens\PrintConnector"; Permissions: users-modify

[Icons]
Name: "{autodesktop}\Estado del Conector de Impresion"; Filename: "{app}\InventarioArens-Print-Connector.exe"; Parameters: "status"; WorkingDir: "{app}"

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\uninstall-task.ps1"""; Flags: waituntilterminated runhidden

[Code]
procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  ScriptPath: String;
  Arguments: String;
begin
  if CurStep <> ssPostInstall then
    Exit;

  ScriptPath := ExpandConstant('{app}\install-task.ps1');
  Arguments := '-NoProfile -ExecutionPolicy Bypass -File "' + ScriptPath +
    '" -ConnectorRoot "' + ExpandConstant('{app}') + '"';

  if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), Arguments,
    '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    RaiseException('No se pudo registrar la tarea del Conector de Impresion.');
  if ResultCode <> 0 then
    RaiseException(Format('El Conector de Impresion no supero la instalacion (codigo %d).', [ResultCode]));
end;
