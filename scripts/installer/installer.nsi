!include "MUI2.nsh"
!include "FileFunc.nsh"

; General Configuration
Name "BalanzaPro"
OutFile "/opt/balanzapro-cloud/public/downloads/BalanzaPro-Setup.exe"
InstallDir "C:\BalanzaPro"
InstallDirRegKey HKLM "Software\BalanzaPro" "Install_Dir"
RequestExecutionLevel admin

; UI Settings
!define MUI_ABORTWARNING
!define MUI_ICON "${NSISDIR}\Contrib\Graphics\Icons\modern-install.ico"
!define MUI_UNICON "${NSISDIR}\Contrib\Graphics\Icons\modern-uninstall.ico"

; Pages
!insertmacro MUI_PAGE_WELCOME
!insertmacro MUI_PAGE_DIRECTORY
!insertmacro MUI_PAGE_INSTFILES

; Finish Page with Run options
!define MUI_FINISHPAGE_RUN "$INSTDIR\BalanzaPro-POS.exe"
!define MUI_FINISHPAGE_RUN_TEXT "Abrir BalanzaPro POS ahora"
!insertmacro MUI_PAGE_FINISH

; Uninstaller Pages
!insertmacro MUI_UNPAGE_CONFIRM
!insertmacro MUI_UNPAGE_INSTFILES

; Language
!insertmacro MUI_LANGUAGE "Spanish"

Section "BalanzaPro Completo" SecMain
    SectionIn RO

    SetOutPath "$INSTDIR"

    ; 1. Cerrar procesos previos si estaban en ejecucion
    DetailPrint "Cerrando procesos previos..."
    ExecWait 'taskkill /F /IM MotorLocal.exe /IM php.exe /IM BalanzaPro-POS.exe /IM BalanzaPro-Administrativo.exe'

    ; 2. Comprobar si ya existe instalacion previa (Modo Actualizacion Inteligente)
    StrCpy $1 "0"
    IfFileExists "$INSTDIR\MotorLocal.exe" 0 +2
        StrCpy $1 "1"
    IfFileExists "$INSTDIR\BalanzaPro-POS.exe" 0 +2
        StrCpy $1 "1"

    StrCmp $1 "1" 0 FreshInstall
    DetailPrint "=========================================================="
    DetailPrint " Modo Actualizacion Inteligente activado."
    DetailPrint " Conservando base de datos, ventas y componentes previos..."
    DetailPrint "=========================================================="
    Goto CopyPackageFiles

FreshInstall:
    DetailPrint "=========================================================="
    DetailPrint " Instalacion limpia desde cero."
    DetailPrint "=========================================================="

CopyPackageFiles:
    ; Respaldar configuracion y base de datos si ya existen
    IfFileExists "$INSTDIR\config.json" 0 +3
        DetailPrint "Respaldando configuracion de empresa existente..."
        CopyFiles /SILENT "$INSTDIR\config.json" "$INSTDIR\config.json.bak"

    IfFileExists "$INSTDIR\backend\database\database.sqlite" 0 +3
        DetailPrint "Respaldando base de datos y ventas existentes..."
        CopyFiles /SILENT "$INSTDIR\backend\database\database.sqlite" "$INSTDIR\backend\database\database.sqlite.bak"

    ; Instalar / Actualizar archivos de BalanzaPro
    DetailPrint "Instalando archivos del sistema..."
    File /r "/tmp/windows-packager/BalanzaPro-Go-Completo\*.*"

    ; Restaurar datos de usuario existentes tras la actualizacion
    IfFileExists "$INSTDIR\config.json.bak" 0 +4
        DetailPrint "Restaurando tu configuracion de empresa existente..."
        CopyFiles /SILENT "$INSTDIR\config.json.bak" "$INSTDIR\config.json"
        Delete "$INSTDIR\config.json.bak"

    IfFileExists "$INSTDIR\backend\database\database.sqlite.bak" 0 +4
        DetailPrint "Restaurando tu base de datos y ventas existentes..."
        CopyFiles /SILENT "$INSTDIR\backend\database\database.sqlite.bak" "$INSTDIR\backend\database\database.sqlite"
        Delete "$INSTDIR\backend\database\database.sqlite.bak"

    ; Si es una actualizacion, OMITIR completamente la instalacion de WebView2
    StrCmp $1 "1" WebView2Done 0

    ; Revision inteligente de WebView2 en PC nueva
    ; 1. Check carpetas fisicas donde se instala WebView2
    IfFileExists "$PROGRAMFILES\Microsoft\EdgeWebView\Application\*.*" WebView2Done 0
    IfFileExists "$PROGRAMFILES64\Microsoft\EdgeWebView\Application\*.*" WebView2Done 0
    IfFileExists "$LOCALAPPDATA\Microsoft\EdgeWebView\Application\*.*" WebView2Done 0

    ; 2. Check de registro (64-bit, 32-bit y HKCU)
    DetailPrint "Verificando Microsoft Edge WebView2..."
    SetRegView 64
    ReadRegStr $0 HKLM "SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" "pv"
    StrCmp $0 "" 0 WebView2Done
    ReadRegStr $0 HKLM "SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" "pv"
    StrCmp $0 "" 0 WebView2Done

    SetRegView 32
    ReadRegStr $0 HKLM "SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" "pv"
    StrCmp $0 "" 0 WebView2Done

    ReadRegStr $0 HKCU "Software\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" "pv"
    StrCmp $0 "" 0 WebView2Done

    ; Solo si falta por completo en la nueva PC, se instala
    IfFileExists "$INSTDIR\MicrosoftEdgeWebview2Setup.exe" 0 WebView2Done
    DetailPrint "Instalando componente visual WebView2 necesario..."
    ExecWait '"$INSTDIR\MicrosoftEdgeWebview2Setup.exe" /silent /install'

WebView2Done:
    SetRegView 32

    ; Auto-detect if user downloaded a new database.sqlite or config.json in the same folder as setup
    IfFileExists "$EXEDIR\database.sqlite" 0 +3
        DetailPrint "Instalando nueva base de datos detectada en la carpeta de instalacion..."
        CopyFiles /SILENT "$EXEDIR\database.sqlite" "$INSTDIR\backend\database\database.sqlite"

    IfFileExists "$EXEDIR\config.json" 0 +3
        DetailPrint "Instalando nueva configuracion detectada en la carpeta de instalacion..."
        CopyFiles /SILENT "$EXEDIR\config.json" "$INSTDIR\config.json"

    ; Create Desktop Shortcuts (Direct to .exe without console windows)
    DetailPrint "Creando accesos directos en el Escritorio..."
    Delete "$DESKTOP\BalanzaPro POS.lnk"
    Delete "$DESKTOP\BalanzaPro Administrativo.lnk"
    CreateShortcut "$DESKTOP\BalanzaPro POS.lnk" "$INSTDIR\BalanzaPro-POS.exe" "" "$INSTDIR\BalanzaPro-POS.exe" 0
    CreateShortcut "$DESKTOP\BalanzaPro Administrativo.lnk" "$INSTDIR\BalanzaPro-Administrativo.exe" "" "$INSTDIR\BalanzaPro-Administrativo.exe" 0
    CreateShortcut "$DESKTOP\Configurar Empresa.lnk" "$INSTDIR\Configurar-Empresa.bat" "" "$INSTDIR\BalanzaPro-Administrativo.exe" 0

    ; Create Start Menu Shortcuts
    CreateDirectory "$SMPROGRAMS\BalanzaPro"
    CreateShortcut "$SMPROGRAMS\BalanzaPro\BalanzaPro POS.lnk" "$INSTDIR\BalanzaPro-POS.exe"
    CreateShortcut "$SMPROGRAMS\BalanzaPro\BalanzaPro Administrativo.lnk" "$INSTDIR\BalanzaPro-Administrativo.exe"
    CreateShortcut "$SMPROGRAMS\BalanzaPro\Configurar Empresa.lnk" "$INSTDIR\Configurar-Empresa.bat"
    CreateShortcut "$SMPROGRAMS\BalanzaPro\Desinstalar BalanzaPro.lnk" "$INSTDIR\Desinstalar.exe"

    ; Registry keys for uninstaller
    WriteRegStr HKLM "Software\BalanzaPro" "Install_Dir" "$INSTDIR"
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "DisplayName" "BalanzaPro - Sistema de Inventario y POS"
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "UninstallString" '"$INSTDIR\Desinstalar.exe"'
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "DisplayIcon" "$INSTDIR\BalanzaPro-POS.exe"
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "Publisher" "BalanzaPro / InventarioArens"
    WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "NoModify" 1
    WriteRegDWORD HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro" "NoRepair" 1

    ; Create uninstaller
    WriteUninstaller "$INSTDIR\Desinstalar.exe"
SectionEnd

Section "Uninstall"
    ; Stop processes
    ExecWait 'taskkill /F /IM MotorLocal.exe /IM php.exe /IM BalanzaPro-POS.exe /IM BalanzaPro-Administrativo.exe'

    ; Remove Shortcuts
    Delete "$DESKTOP\BalanzaPro POS.lnk"
    Delete "$DESKTOP\BalanzaPro Administrativo.lnk"
    Delete "$DESKTOP\Configurar Empresa.lnk"
    RMDir /r "$SMPROGRAMS\BalanzaPro"

    ; Remove Registry
    DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaPro"
    DeleteRegKey HKLM "Software\BalanzaPro"

    ; Remove Installed files
    RMDir /r "$INSTDIR\backend"
    RMDir /r "$INSTDIR\php"
    Delete "$INSTDIR\*.*"
    RMDir "$INSTDIR"
SectionEnd
