!include "MUI2.nsh"
!include "FileFunc.nsh"
!include "x64.nsh"

!ifndef PACKAGE_DIR
  !error "Define PACKAGE_DIR"
!endif
!ifndef OUTPUT_PATH
  !error "Define OUTPUT_PATH"
!endif
!ifndef APP_VERSION
  !define APP_VERSION "1.0.0"
!endif
!ifndef BUILD_VERSION
  !define BUILD_VERSION "1.0.0.0"
!endif

Name "BalanzaPro"
OutFile "${OUTPUT_PATH}"
InstallDir "$PROGRAMFILES64\BalanzaPro"
InstallDirRegKey HKLM "Software\BalanzaPro" "Install_Dir"
RequestExecutionLevel admin
Unicode true
SetCompressor zlib


VIProductVersion "${BUILD_VERSION}"
VIAddVersionKey "ProductName" "BalanzaPro"
VIAddVersionKey "CompanyName" "BalanzaPro"
VIAddVersionKey "FileDescription" "Instalador local de BalanzaPro"
VIAddVersionKey "FileVersion" "${APP_VERSION}"
VIAddVersionKey "ProductVersion" "${APP_VERSION}"
VIAddVersionKey "LegalCopyright" "BalanzaPro"

!define MUI_ABORTWARNING
!define MUI_ICON "${NSISDIR}\Contrib\Graphics\Icons\modern-install.ico"
!define MUI_UNICON "${NSISDIR}\Contrib\Graphics\Icons\modern-uninstall.ico"
!define MUI_FINISHPAGE_RUN "$INSTDIR\BalanzaPro-POS.exe"
!define MUI_FINISHPAGE_RUN_TEXT "Abrir BalanzaPro POS ahora"
!define MUI_FINISHPAGE_SHOWREADME "$INSTDIR\LEEME.txt"
!define MUI_FINISHPAGE_SHOWREADME_TEXT "Ver instrucciones para otras PC de la tienda"

!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES
!insertmacro MUI_PAGE_FINISH
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES
!insertmacro MUI_LANGUAGE "Spanish"

Var DataRoot
Var CompanyZip
Var UpgradeMode
Var UpgradePrepared
Var HelperExit
Var RobocopyExit
Var RollbackDir
Var FailedDir

Function ResolveDataRoot
  ReadEnvStr $DataRoot "ProgramData"
  StrCmp $DataRoot "" 0 DataRootResolved
  StrCpy $DataRoot "$WINDIR\..\ProgramData"
DataRootResolved:
  StrCpy $DataRoot "$DataRoot\BalanzaPro"
FunctionEnd

Function .onInit
  ${IfNot} ${RunningX64}
    MessageBox MB_ICONSTOP "BalanzaPro requiere Windows de 64 bits."
    Abort
  ${EndIf}
  SetRegView 64
  SetShellVarContext all
FunctionEnd

Function GenerateRecoveryNames
  ${GetTime} "" "L" $0 $1 $2 $3 $4 $5 $6
  StrCpy $RollbackDir "$INSTDIR.rollback-$2$1$0-$4$5$6"
  StrCpy $FailedDir "$INSTDIR.failed-$2$1$0-$4$5$6"
FunctionEnd

Function CheckWebView2
  StrCpy $0 ""
  SetRegView 64
  ReadRegStr $0 HKLM "SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" "pv"
  StrCmp $0 "" 0 WebView2Ready
  ReadRegStr $0 HKCU "SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" "pv"
  StrCmp $0 "" 0 WebView2Ready
  DetailPrint "Instalando Microsoft Edge WebView2 para uso sin conexión..."
  IfFileExists "$PLUGINSDIR\package\MicrosoftEdgeWebView2RuntimeInstallerX64.exe" 0 WebView2Missing
  ExecWait '"$PLUGINSDIR\package\MicrosoftEdgeWebView2RuntimeInstallerX64.exe" /silent /install' $1
  IntCmp $1 0 WebView2Ready
  IntCmp $1 3010 WebView2Ready
  MessageBox MB_ICONSTOP "No se pudo instalar WebView2. Reinicia Windows o instala el Runtime y vuelve a ejecutar BalanzaPro-Setup.exe."
  Abort

WebView2Missing:
  MessageBox MB_ICONSTOP "El paquete no incluye el instalador completo de WebView2. No se continuará para evitar una instalación incompleta."
  Abort

WebView2Ready:
  SetRegView 64
FunctionEnd

Function InvokePreflightStopAndPort
  ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$PLUGINSDIR\package\installer-preflight.ps1" -InstallDir "$INSTDIR" -Port 8787 -CheckPort' $0
  IntCmp $0 0 PreflightReady
  MessageBox MB_ICONSTOP "No se pudo detener la instalación actual o el puerto 8787 está ocupado. Cierra el otro Motor Local antes de continuar."
  Abort
PreflightReady:
FunctionEnd

Function FindCompanyZip
  ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$PLUGINSDIR\package\installer-preflight.ps1" -SetupDir "$EXEDIR" -OutputFile "$PLUGINSDIR\company-data.zip" -FindCompanyZip' $0
  IntCmp $0 0 CompanyZipReady
  MessageBox MB_ICONSTOP "Para instalar una empresa por primera vez, descarga el ZIP privado desde Configuración de empresa y déjalo junto al Setup. Debe haber un solo archivo BalanzaPro-datos-*.zip."
  Abort
CompanyZipReady:
  StrCpy $CompanyZip "$PLUGINSDIR\company-data.zip"
FunctionEnd

Function InvokeFreshImport
  DetailPrint "Verificando empresa, hashes e integridad SQLite..."
  ExecWait '"$PLUGINSDIR\package\frankenphp\php.exe" -c "$PLUGINSDIR\package\frankenphp\php.ini" "$PLUGINSDIR\package\installer-helper.php" fresh "$DataRoot" "$CompanyZip" "$PLUGINSDIR\package\backend" "$PLUGINSDIR\package\frankenphp\php.exe" "$PLUGINSDIR\package\frankenphp\php.ini"' $HelperExit
  IntCmp $HelperExit 0 FreshImportReady
  MessageBox MB_ICONSTOP "No se pudo verificar o importar el ZIP de la empresa. La instalación se canceló y se conservaron los archivos descargados."
  Abort
FreshImportReady:
FunctionEnd

Function InvokeUpgradePreparation
  StrCpy $UpgradeMode 1
  IfFileExists "$DataRoot\database.sqlite" UpgradeFromProgramData
  IfFileExists "$INSTDIR\backend\database\database.sqlite" UpgradeFromLegacy
  MessageBox MB_ICONSTOP "Se encontró una instalación anterior, pero no su base local. No se importará un ZIP junto al Setup durante una actualización. Restaura primero la base anterior y vuelve a ejecutar."
  Abort

UpgradeFromProgramData:
  ExecWait '"$PLUGINSDIR\package\frankenphp\php.exe" -c "$PLUGINSDIR\package\frankenphp\php.ini" "$PLUGINSDIR\package\installer-helper.php" prepare-upgrade "$DataRoot" "$DataRoot\database.sqlite" "$DataRoot\config.json" "$INSTDIR\backend\.env" "$INSTDIR\backend\public\storage" "$PLUGINSDIR\package\backend" "$PLUGINSDIR\package\frankenphp\php.exe" "$PLUGINSDIR\package\frankenphp\php.ini"' $HelperExit
  Goto UpgradePreparationResult

UpgradeFromLegacy:
  ExecWait '"$PLUGINSDIR\package\frankenphp\php.exe" -c "$PLUGINSDIR\package\frankenphp\php.ini" "$PLUGINSDIR\package\installer-helper.php" prepare-upgrade "$DataRoot" "$INSTDIR\backend\database\database.sqlite" "$INSTDIR\config.json" "$INSTDIR\backend\.env" "$INSTDIR\backend\public\storage" "$PLUGINSDIR\package\backend" "$PLUGINSDIR\package\frankenphp\php.exe" "$PLUGINSDIR\package\frankenphp\php.ini"' $HelperExit

UpgradePreparationResult:
  IntCmp $HelperExit 0 UpgradePreparationReady
  MessageBox MB_ICONSTOP "No se pudo preparar la copia o migrar una copia de la base. La instalación anterior y sus datos siguen intactos. Revisa el detalle en $DataRoot\storage\logs\installer.log."
  Abort
UpgradePreparationReady:
  StrCpy $UpgradePrepared 1
FunctionEnd

Function InvokeDatabaseActivation
  StrCmp $UpgradeMode 1 0 DatabaseActivationReady
  ExecWait '"$PLUGINSDIR\package\frankenphp\php.exe" -c "$PLUGINSDIR\package\frankenphp\php.ini" "$PLUGINSDIR\package\installer-helper.php" activate-upgrade "$DataRoot"' $HelperExit
  IntCmp $HelperExit 0 DatabaseActivationReady
  Call RollBackInstallation
  MessageBox MB_ICONSTOP "No se pudo activar la nueva base local. BalanzaPro recuperó la carpeta anterior."
  Abort
DatabaseActivationReady:
  StrCmp $UpgradeMode 1 0 DatabaseActivationComplete
  StrCpy $UpgradePrepared 1
DatabaseActivationComplete:
FunctionEnd

Function ConfigureDataPermissions
  CreateDirectory "$DataRoot"
  ; Recupera la propiedad antes de restablecer ACL heredadas: las ACL previas
  ; pueden impedir leer SQLite o escribir los registros bajo ProgramData.
  ExecWait '"$SYSDIR\icacls.exe" "$DataRoot" /setowner "*S-1-5-32-544" /T /C' $0
  IntCmp $0 0 DataOwnerReady
  MessageBox MB_ICONSTOP "Windows no pudo recuperar la propiedad de los datos locales. No se iniciará BalanzaPro."
  Call RollBackInstallation
  Abort
DataOwnerReady:
  ExecWait '"$SYSDIR\icacls.exe" "$DataRoot" /reset /T /C' $0
  IntCmp $0 0 DataAclReset
  MessageBox MB_ICONSTOP "Windows no pudo restablecer los permisos de los datos locales. No se iniciará BalanzaPro."
  Call RollBackInstallation
  Abort
DataAclReset:
  ExecWait '"$SYSDIR\icacls.exe" "$DataRoot" /grant:r "*S-1-5-18:(OI)(CI)F" "*S-1-5-32-544:(OI)(CI)F" "*S-1-5-32-545:(OI)(CI)M" /T /C' $0
  IntCmp $0 0 DataAclReady
  MessageBox MB_ICONSTOP "Windows no pudo habilitar el acceso seguro a los datos locales. No se iniciará BalanzaPro."
  Call RollBackInstallation
  Abort
DataAclReady:
  IfFileExists "$DataRoot\app.key" 0 DataAclComplete
  ExecWait '"$SYSDIR\icacls.exe" "$DataRoot\app.key" /inheritance:r /grant:r "*S-1-5-18:F" "*S-1-5-32-544:F" "*S-1-5-32-545:R"' $0
  IntCmp $0 0 DataAclComplete
  MessageBox MB_ICONSTOP "Windows no pudo proteger la clave local de BalanzaPro."
  Call RollBackInstallation
  Abort
DataAclComplete:
FunctionEnd

Function ConfigureLanFirewall
  ExecWait '"$SYSDIR\netsh.exe" advfirewall firewall add rule name="BalanzaPro Motor Local" dir=in action=allow protocol=TCP localport=8787 profile=private' $0
  IntCmp $0 0 LanFirewallReady
  MessageBox MB_ICONSTOP "Windows no pudo habilitar el acceso de BalanzaPro en la red privada. Se cancelará la instalación para no dejar una PC central sin acceso LAN."
  Call RollBackInstallation
  Abort
LanFirewallReady:
FunctionEnd

Function CopyNewProgram
  IfFileExists "$RollbackDir\." RollbackNameExists 0
  IfFileExists "$INSTDIR\MotorLocal.exe" MovePreviousProgram
  IfFileExists "$INSTDIR\BalanzaPro-POS.exe" MovePreviousProgram
  IfFileExists "$INSTDIR\backend\artisan" MovePreviousProgram
  Goto CreateTargetDirectory

MovePreviousProgram:
  DetailPrint "Guardando la versión instalada para recuperación..."
  Rename "$INSTDIR" "$RollbackDir"
  IfErrors RollbackRenameFailed

CreateTargetDirectory:
  CreateDirectory "$INSTDIR"
  ExecWait '"$SYSDIR\robocopy.exe" "$PLUGINSDIR\package" "$INSTDIR" /E /COPY:DAT /DCOPY:DAT /R:2 /W:1 /NFL /NDL /NJH /NJS /NP' $RobocopyExit
  IntCmp $RobocopyExit 8 CopyProgramFailed CopyProgramReady CopyProgramFailed

RollbackNameExists:
  MessageBox MB_ICONSTOP "Ya existe una carpeta de recuperación con el mismo nombre. Consérvala y vuelve a ejecutar el instalador más tarde."
  Abort
RollbackRenameFailed:
  MessageBox MB_ICONSTOP "No se pudo guardar la versión anterior. No se reemplazaron sus archivos."
  Abort
CopyProgramFailed:
  Call RollBackInstallation
  MessageBox MB_ICONSTOP "No se pudieron copiar los archivos nuevos. Se recuperó la versión anterior y se conservaron los datos."
  Abort

CopyProgramReady:
FunctionEnd

Function StartAndVerify
  DetailPrint "Iniciando el Motor Local y verificando el backend..."
  Exec '"$INSTDIR\MotorLocal.exe"'
  ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\installer-preflight.ps1" -InstallDir "$INSTDIR" -WaitForBackend' $0
  IntCmp $0 0 StartVerified
  Call RollBackInstallation
  MessageBox MB_ICONSTOP "El Motor Local no respondió correctamente. Se recuperó la versión anterior; tus datos permanecen en ProgramData."
  Abort
StartVerified:
FunctionEnd

Function RollBackInstallation
  ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$PLUGINSDIR\package\installer-preflight.ps1" -InstallDir "$INSTDIR"' $0
  StrCmp $UpgradePrepared 1 0 SkipDatabaseRecovery
  ExecWait '"$PLUGINSDIR\package\frankenphp\php.exe" -c "$PLUGINSDIR\package\frankenphp\php.ini" "$PLUGINSDIR\package\installer-helper.php" rollback-upgrade "$DataRoot"' $HelperExit
SkipDatabaseRecovery:
  IfFileExists "$INSTDIR\MotorLocal.exe" MoveFailedProgram 0
  IfFileExists "$INSTDIR\BalanzaPro-POS.exe" MoveFailedProgram 0
  Goto RestorePreviousProgram
MoveFailedProgram:
  Call GenerateRecoveryNames
  IfFileExists "$FailedDir\." 0 MoveFailedDirectory
  StrCpy $FailedDir "$INSTDIR.failed-recovery"
MoveFailedDirectory:
  Rename "$INSTDIR" "$FailedDir"
RestorePreviousProgram:
  IfFileExists "$RollbackDir\." 0 RollBackComplete
  Rename "$RollbackDir" "$INSTDIR"
RollBackComplete:
FunctionEnd

Section "Instalar BalanzaPro" SecMain
  SectionIn RO
  Call ResolveDataRoot
  StrCpy $UpgradeMode 0
  StrCpy $UpgradePrepared 0

  InitPluginsDir
  CreateDirectory "$PLUGINSDIR\package"
  SetOutPath "$PLUGINSDIR\package"
  File /r /x "MicrosoftEdgeWebView2RuntimeInstallerX64.exe" "${PACKAGE_DIR}\*.*"
  SetCompress off
  File "${PACKAGE_DIR}\MicrosoftEdgeWebView2RuntimeInstallerX64.exe"
  SetCompress auto

  Call InvokePreflightStopAndPort
  Call CheckWebView2
  Call GenerateRecoveryNames

  IfFileExists "$DataRoot\database.sqlite" SetUpUpgrade
  IfFileExists "$INSTDIR\MotorLocal.exe" SetUpUpgrade
  IfFileExists "$INSTDIR\BalanzaPro-POS.exe" SetUpUpgrade
  IfFileExists "$INSTDIR\backend\database\database.sqlite" SetUpUpgrade
  Goto FreshInstall

SetUpUpgrade:
  Call InvokeUpgradePreparation
  Goto InstallProgramFiles

FreshInstall:
  Call FindCompanyZip
  Call InvokeFreshImport

InstallProgramFiles:
  Call CopyNewProgram
  Call InvokeDatabaseActivation
  Call ConfigureDataPermissions
  Call ConfigureLanFirewall

  WriteRegStr HKLM "Software\BalanzaPro" "Install_Dir" "$INSTDIR"

  CreateDirectory "$SMPROGRAMS\BalanzaPro"
  CreateShortcut "$DESKTOP\BalanzaPro POS.lnk" "$INSTDIR\BalanzaPro-POS.exe" "" "$INSTDIR\BalanzaPro-POS.exe" 0
  CreateShortcut "$DESKTOP\BalanzaPro Administrativo.lnk" "$INSTDIR\BalanzaPro-Administrativo.exe" "" "$INSTDIR\BalanzaPro-Administrativo.exe" 0
  CreateShortcut "$SMPROGRAMS\BalanzaPro\BalanzaPro POS.lnk" "$INSTDIR\BalanzaPro-POS.exe" "" "$INSTDIR\BalanzaPro-POS.exe" 0
  CreateShortcut "$SMPROGRAMS\BalanzaPro\BalanzaPro Administrativo.lnk" "$INSTDIR\BalanzaPro-Administrativo.exe" "" "$INSTDIR\BalanzaPro-Administrativo.exe" 0
  CreateShortcut "$SMPROGRAMS\BalanzaPro\Desinstalar BalanzaPro.lnk" "$INSTDIR\Uninstall.exe"

  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "DisplayName" "BalanzaPro - Sistema de Inventario y POS"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "UninstallString" '"$INSTDIR\Uninstall.exe"'
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "DisplayIcon" "$INSTDIR\BalanzaPro-POS.exe"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "Publisher" "BalanzaPro"
  WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "DisplayVersion" "${APP_VERSION}"
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "NoModify" 1
  WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "NoRepair" 1

  Call StartAndVerify

  StrCmp $UpgradeMode 1 0 SkipUpgradeCommit
  ExecWait '"$PLUGINSDIR\package\frankenphp\php.exe" -c "$PLUGINSDIR\package\frankenphp\php.ini" "$PLUGINSDIR\package\installer-helper.php" commit-upgrade "$DataRoot"' $HelperExit
  IntCmp $HelperExit 0 SkipUpgradeCommit
  DetailPrint "La copia anterior permanece en $RollbackDir por seguridad."
SkipUpgradeCommit:
  WriteUninstaller "$INSTDIR\Uninstall.exe"
SectionEnd

Section "Uninstall"
  ReadEnvStr $DataRoot "ProgramData"
  StrCmp $DataRoot "" 0 +2
  StrCpy $DataRoot "$WINDIR\..\ProgramData"
  StrCpy $DataRoot "$DataRoot\BalanzaPro"
  ExecWait '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\installer-preflight.ps1" -InstallDir "$INSTDIR"' $0

  IfFileExists "$DataRoot\database.sqlite" DataAlreadyPersistent
  IfFileExists "$INSTDIR\backend\database\database.sqlite" PreserveLegacyData
  Goto RemoveApplication

PreserveLegacyData:
  ExecWait '"$INSTDIR\frankenphp\php.exe" -c "$INSTDIR\frankenphp\php.ini" "$INSTDIR\installer-helper.php" prepare-upgrade "$DataRoot" "$INSTDIR\backend\database\database.sqlite" "$INSTDIR\config.json" "$INSTDIR\backend\.env" "$INSTDIR\backend\public\storage" "$INSTDIR\backend" "$INSTDIR\frankenphp\php.exe" "$INSTDIR\frankenphp\php.ini"' $HelperExit
  IntCmp $HelperExit 0 LegacyDataPrepared
  MessageBox MB_ICONSTOP "No se desinstalará BalanzaPro porque no se pudo conservar tu base local. Los datos siguen en la carpeta actual."
  Abort
LegacyDataPrepared:
  ExecWait '"$INSTDIR\frankenphp\php.exe" -c "$INSTDIR\frankenphp\php.ini" "$INSTDIR\installer-helper.php" activate-upgrade "$DataRoot"' $HelperExit
  IntCmp $HelperExit 0 LegacyDataActivated
  ExecWait '"$INSTDIR\frankenphp\php.exe" -c "$INSTDIR\frankenphp\php.ini" "$INSTDIR\installer-helper.php" rollback-upgrade "$DataRoot"' $0
  MessageBox MB_ICONSTOP "No se desinstalará BalanzaPro porque no se pudo activar la copia preservada."
  Abort
LegacyDataActivated:
  ExecWait '"$INSTDIR\frankenphp\php.exe" -c "$INSTDIR\frankenphp\php.ini" "$INSTDIR\installer-helper.php" commit-upgrade "$DataRoot"' $0
  Goto RemoveApplication

DataAlreadyPersistent:
  DetailPrint "Los datos locales se conservan en ProgramData."

RemoveApplication:
  DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro"
  DeleteRegKey HKLM "Software\BalanzaPro"
  ExecWait '"$SYSDIR\netsh.exe" advfirewall firewall delete rule name="BalanzaPro Motor Local"' $0
  Delete "$DESKTOP\BalanzaPro POS.lnk"
  Delete "$DESKTOP\BalanzaPro Administrativo.lnk"
  RMDir /r "$SMPROGRAMS\BalanzaPro"

  ; No elimina ProgramData\BalanzaPro. Solo elimina los archivos del programa.
  RMDir /r "$INSTDIR"
SectionEnd
