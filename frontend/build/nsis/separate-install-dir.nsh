; separate-install-dir.nsh
;
; Forces each Electron client to install into its own machine-wide folder.
; The Motor Local is an independent package and is never installed, updated or
; removed from an individual Electron client lifecycle.
;
; This macro runs before InstallDir is resolved.

!macro customInstallDir
  StrCpy $INSTDIR "$PROGRAMFILES\Sistema de Inventario\${PRODUCT_FILENAME}"
!macroend
