!ifndef VERSION
!define VERSION "1.0.0"
!endif
!ifndef OUT_FILE
!define OUT_FILE "BalanzaPro-Impresor-Setup.exe"
!endif

!define APP_NAME "BalanzaPro Impresor Local"
!define COMP_NAME "BalanzaPro"
!define EXE_NAME "BalanzaPro-Printer-Agent.exe"

Unicode True
RequestExecutionLevel user

Name "${APP_NAME} ${VERSION}"
OutFile "${OUT_FILE}"
InstallDir "$LOCALAPPDATA\Programs\BalanzaPro\PrinterAgent"
InstallDirRegKey HKCU "Software\BalanzaPro\PrinterAgent" "InstallDir"

VIProductVersion "${VERSION}.0"
VIAddVersionKey "ProductName" "${APP_NAME}"
VIAddVersionKey "CompanyName" "${COMP_NAME}"
VIAddVersionKey "FileVersion" "${VERSION}.0"
VIAddVersionKey "ProductVersion" "${VERSION}.0"
VIAddVersionKey "FileDescription" "Agente local de impresion para estaciones BalanzaPro"

Page directory
Page instfiles
UninstPage uninstConfirm
UninstPage instfiles

Section "Instalacion Principal" SecCore
    SetOutPath "$INSTDIR"

    ; Solo detiene la edicion independiente, no el agente del Motor Local.
    ExecWait '"$SYSDIR\taskkill.exe" /F /IM "${EXE_NAME}"'
    File "/oname=${EXE_NAME}" "../bin/${EXE_NAME}"
    WriteUninstaller "$INSTDIR\uninstall.exe"

    ; Se ejecuta en la sesion del cajero para acceder a sus impresoras Windows.
    CreateDirectory "$SMSTARTUP"
    CreateShortCut "$SMSTARTUP\BalanzaPro Impresor Local.lnk" "$INSTDIR\${EXE_NAME}" "-port=17777 -bind=127.0.0.1" "$INSTDIR\${EXE_NAME}" 0 0
    ExecShell "open" "$SMSTARTUP\BalanzaPro Impresor Local.lnk"

    WriteRegStr HKCU "Software\BalanzaPro\PrinterAgent" "InstallDir" "$INSTDIR"
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinterAgent" "DisplayName" "${APP_NAME}"
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinterAgent" "UninstallString" '"$INSTDIR\uninstall.exe"'
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinterAgent" "DisplayVersion" "${VERSION}"
    WriteRegStr HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinterAgent" "Publisher" "${COMP_NAME}"

    DetailPrint "Agente instalado para esta sesion de Windows; no instala Motor ni base de datos."
SectionEnd

Section "Uninstall"
    ExecWait '"$SYSDIR\taskkill.exe" /F /IM "${EXE_NAME}"'
    Delete "$SMSTARTUP\BalanzaPro Impresor Local.lnk"
    Delete "$INSTDIR\${EXE_NAME}"
    Delete "$INSTDIR\uninstall.exe"
    RMDir "$INSTDIR"

    DeleteRegKey HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinterAgent"
    DeleteRegKey HKCU "Software\BalanzaPro\PrinterAgent"
SectionEnd
