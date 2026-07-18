@echo off
setlocal

set "ROOT=%~dp0.."
set "GRAPHIFY=%ROOT%\.tools\graphify-venv\Scripts\graphify.exe"

if not exist "%GRAPHIFY%" (
    echo Graphify is not installed in this project.
    echo Run:
    echo   "%ROOT%\.tools\graphify-venv\Scripts\python.exe" -m pip install graphifyy
    exit /b 1
)

"%GRAPHIFY%" %*
