; separate-install-dir.nsh
;
; Forces each Electron client to install into its own per-user folder so the
; bundled app.asar and resources/ never collide between the Administrativo and
; POS builds. Without this, both installers default to
; $LOCALAPPDATA\Programs\inventarioarens-frontend and the second install
; silently overwrites the first's resources/, causing the Administrativo exe to
; load the POS renderer.
;
; This macro must run BEFORE InstallDir is resolved, so we hook into
; customInstall which is invoked right before the install section reads
; InstallDir. We also update InstallDir reg-key metadata so Add/Remove Programs
; shows the right name.

!macro customInstall
  ${If} $INSTDIR == ""
    StrCpy $INSTDIR "$LOCALAPPDATA\Programs\${PRODUCT_FILENAME}"
  ${EndIf}
!macroend

!macro customInstallDir
  StrCpy $INSTDIR "$LOCALAPPDATA\Programs\${PRODUCT_FILENAME}"
!macroend
