!define APP_NAME "BalanzaPro Agente de Impresion"
!define COMP_NAME "BalanzaPro"
!define VERSION "1.0.0"
!define EXE_NAME "printer-agent.exe"
!define OUT_FILE "BalanzaPro-Impresor-Setup.exe"

Unicode True
RequestExecutionLevel admin

Name "${APP_NAME}"
OutFile "${OUT_FILE}"
InstallDir "$PROGRAMFILES64\BalanzaPro\PrinterAgent"
InstallDirRegKey HKLM "Software\BalanzaPro\PrinterAgent" "InstallDir"

VIProductVersion "${VERSION}.0"
VIAddVersionKey "ProductName" "${APP_NAME}"
VIAddVersionKey "CompanyName" "${COMP_NAME}"
VIAddVersionKey "FileVersion" "${VERSION}.0"
VIAddVersionKey "ProductVersion" "${VERSION}.0"
VIAddVersionKey "FileDescription" "Instalador del Agente de Impresion para BalanzaPro"

Page directory
Page instfiles

UninstPage uninstConfirm
UninstPage instfiles

Section "Instalacion Principal" SecCore
    SetOutPath "$INSTDIR"

    ; Detener servicio previo si existe
    ExecWait '"$INSTDIR\${EXE_NAME}" -uninstall'

    ; Copiar ejecutable
    File "/oname=${EXE_NAME}" "../bin/printer-agent.exe"

    ; Crear desinstalador
    WriteUninstaller "$INSTDIR\uninstall.exe"

    ; Registrar servicio de Windows e iniciar
    ExecWait '"$INSTDIR\${EXE_NAME}" -install'

    ; Registro de desinstalacion en Windows
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinter" "DisplayName" "${APP_NAME}"
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinter" "UninstallString" '"$INSTDIR\uninstall.exe"'
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinter" "DisplayVersion" "${VERSION}"
    WriteRegStr HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinter" "Publisher" "${COMP_NAME}"
    WriteRegStr HKLM "Software\BalanzaPro\PrinterAgent" "InstallDir" "$INSTDIR"

    DetailPrint "Servicio de impresion instalado e iniciado correctamente en segundo plano."
SectionEnd

Section "Uninstall"
    ; Detener y eliminar servicio de Windows
    ExecWait '"$INSTDIR\${EXE_NAME}" -uninstall'

    ; Eliminar archivos
    Delete "$INSTDIR\${EXE_NAME}"
    Delete "$INSTDIR\uninstall.exe"
    RMDir "$INSTDIR"

    ; Limpiar claves de registro
    DeleteRegKey HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\BalanzaProPrinter"
    DeleteRegKey HKLM "Software\BalanzaPro\PrinterAgent"
SectionEnd
