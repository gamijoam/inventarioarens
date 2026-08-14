; separate-install-dir.nsh
;
; Forces each Electron client to install into its own machine-wide folder so the
; bundled app.asar and resources/ never collide between the Administrativo and
; POS builds. Without this, both installers default to
; $PROGRAMFILES\InventarioArens\inventarioarens-frontend and the second install
; silently overwrites the first's resources/, causing the Administrativo exe to
; load the POS renderer.
;
; This macro must run BEFORE InstallDir is resolved, so we hook into
; customInstall which is invoked right before the install section reads
; InstallDir. We also update InstallDir reg-key metadata so Add/Remove Programs
; shows the right name.

!macro customInstallDir
  StrCpy $INSTDIR "$PROGRAMFILES\InventarioArens\${PRODUCT_FILENAME}"
!macroend

!macro customInstall
  ${If} $INSTDIR == ""
    StrCpy $INSTDIR "$PROGRAMFILES\InventarioArens\${PRODUCT_FILENAME}"
  ${EndIf}
  nsExec::ExecToLog '"$SYSDIR\WindowsPowerShell\v1.0\powershell.exe" -NoProfile -ExecutionPolicy Bypass -File "$INSTDIR\resources\service\install-backend-service.ps1" -SourceBackendRoot "$INSTDIR\resources\backend" -SourcePhpRoot "$INSTDIR\resources\runtime\php" -DataRoot "$APPDATA\InventarioArens"'
  Pop $0
  ${If} $0 != 0
    MessageBox MB_ICONSTOP "No se pudieron instalar los servicios de INVENTARIOARENS. Codigo: $0"
    Abort
  ${EndIf}
!macroend
