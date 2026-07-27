#define MyAppName "Inventario Arens"
#define MyAppVersion "1.0.0"
#define MyAppPublisher "Inventario Arens"
#define MyAppExeName "start-inventario.ps1"

[Setup]
AppId={{B2F6F6C4-4CE3-4F31-BAB6-9B8BC2B9AA21}
AppName={#MyAppName}
AppVersion={#MyAppVersion}
AppPublisher={#MyAppPublisher}
DefaultDirName={autopf}\InventarioArens
DefaultGroupName={#MyAppName}
OutputDir=.
OutputBaseFilename=InventarioArens-Setup-{#MyAppVersion}
Compression=lzma2
SolidCompression=yes
PrivilegesRequired=admin
ArchitecturesInstallIn64BitMode=x64
DisableProgramGroupPage=yes
WizardStyle=modern

[Files]
Source: "stage\*"; DestDir: "{app}"; Flags: recursesubdirs createallsubdirs ignoreversion

[Icons]
Name: "{autodesktop}\{#MyAppName}"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File \\"{app}\installer\windows\start-inventario.ps1\\" -AppRoot \\"{app}\\""; WorkingDir: "{app}"; IconFilename: "{app}\runtime\php\php.exe"
Name: "{group}\{#MyAppName}"; Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File \\"{app}\installer\windows\start-inventario.ps1\\" -AppRoot \\"{app}\\""; WorkingDir: "{app}"

[Run]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File \\"{app}\installer\windows\install-local.ps1\\" -AppRoot \\"{app}\\""; Flags: waituntilterminated

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File \\"{app}\installer\windows\stop-inventario.ps1\\" -AppRoot \\"{app}\\""; Flags: waituntilterminated
