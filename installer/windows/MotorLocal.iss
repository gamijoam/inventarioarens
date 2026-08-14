#ifndef MotorVersion
  #define MotorVersion "0.1.0"
#endif
#define MotorName "Motor Local - Sistema de Inventario"
#define StageRoot "..\..\build\local-motor\stage"

[Setup]
AppId={{18D85753-529E-4E46-B54C-5BD1B3D5247E}
AppName={#MotorName}
AppVersion={#MotorVersion}
AppPublisher=Sistema de Inventario
DefaultDirName={autopf}\Sistema de Inventario\Motor
DefaultGroupName=Sistema de Inventario
OutputDir=..\..\build\local-motor\release
OutputBaseFilename=Motor-Local-Sistema-Inventario-{#MotorVersion}
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
DisableProgramGroupPage=yes
WizardStyle=modern
UninstallDisplayName={#MotorName}

[Files]
Source: "{#StageRoot}\*"; DestDir: "{app}\versions\{#MotorVersion}"; Flags: recursesubdirs createallsubdirs ignoreversion

[Dirs]
Name: "{commonappdata}\InventarioArens"; Permissions: users-modify

[UninstallRun]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\versions\{#MotorVersion}\service\install-local-motor.ps1"" -MotorRoot ""{app}"" -DataRoot ""{commonappdata}\InventarioArens"" -Version ""{#MotorVersion}"" -Uninstall"; Flags: waituntilterminated runhidden

[Code]
procedure CurStepChanged(CurStep: TSetupStep);
var
  ResultCode: Integer;
  ScriptPath: String;
  Arguments: String;
begin
  if CurStep <> ssPostInstall then
    Exit;

  ScriptPath := ExpandConstant('{app}\versions\{#MotorVersion}\service\install-local-motor.ps1');
  Arguments := '-NoProfile -ExecutionPolicy Bypass -File "' + ScriptPath +
    '" -PayloadRoot "' + ExpandConstant('{app}\versions\{#MotorVersion}') +
    '" -MotorRoot "' + ExpandConstant('{app}') +
    '" -DataRoot "' + ExpandConstant('{commonappdata}\InventarioArens') +
    '" -Version "{#MotorVersion}"';

  if not Exec(ExpandConstant('{sys}\WindowsPowerShell\v1.0\powershell.exe'), Arguments,
    '', SW_HIDE, ewWaitUntilTerminated, ResultCode) then
    RaiseException('No se pudo ejecutar el instalador del Motor Local.');
  if ResultCode <> 0 then
    RaiseException(Format('El Motor Local no supero la instalacion o sus health checks (codigo %d). Revise motor-install.log.', [ResultCode]));
end;
