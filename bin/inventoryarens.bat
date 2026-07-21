@echo off
REM ============================================================
REM inventoryarens.bat - wrapper Windows CMD
REM
REM Solo invoca el CLI Python con los argumentos recibidos.
REM Requiere Python 3.8+ en PATH (o en INVENTORYARENS_PYTHON).
REM
REM Uso: inventoryarens.bat install sync
REM      inventoryarens.bat status
REM      inventoryarens.bat logs sync
REM ============================================================

setlocal

REM Buscar Python 3 (prioridad: env var > py launcher > where).
if defined INVENTORYARENS_PYTHON goto :have_python

where py >nul 2>&1
if %errorlevel% equ 0 (
    set "INVENTORYARENS_PYTHON=py"
    goto :have_python
)

where python >nul 2>&1
if %errorlevel% equ 0 (
    set "INVENTORYARENS_PYTHON=python"
    goto :have_python
)

echo [x] No se encontro Python. Instala Python 3.8+ desde https://python.org o
echo     Microsoft Store. Luego ejecuta este script de nuevo.
exit /b 1

:have_python

REM Determinar el directorio donde esta este script.
set "SCRIPT_DIR=%~dp0"

REM Invocar Python con el script .py.
"%INVENTORYARENS_PYTHON%" "%SCRIPT_DIR%inventoryarens" %*

endlocal
