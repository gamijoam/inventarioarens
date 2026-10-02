#!/usr/bin/env bash
set -euo pipefail

PACKAGE_DIR="/tmp/windows-packager/BalanzaPro-Go-Completo"
REPO_DIR="/opt/balanzapro-cloud"
OUTPUT_ZIP="/opt/balanzapro-cloud/public/downloads/BalanzaPro-Go-Instalador-Completo.zip"

echo "=== 1. Limpiando y preparando carpeta del nuevo paquete ==="
rm -rf "$PACKAGE_DIR"
mkdir -p "$PACKAGE_DIR"
mkdir -p "$PACKAGE_DIR/php"
mkdir -p "$PACKAGE_DIR/backend"

echo "=== 2. Copiando PHP Portable para Windows ==="
cp -r /tmp/windows-packager/php/* "$PACKAGE_DIR/php/"

echo "=== 3. Copiando MotorLocal.exe en Go ==="
cp "$REPO_DIR/tools/motor-local/bin/MotorLocal.exe" "$PACKAGE_DIR/MotorLocal.exe"

echo "=== 4. Copiando Aplicaciones de Escritorio en Go (WebView2) ==="
cp "$REPO_DIR/tools/desktop-pos/bin/BalanzaPro-POS-Go.exe" "$PACKAGE_DIR/BalanzaPro-POS.exe"
cp "$REPO_DIR/tools/desktop-admin/bin/BalanzaPro-Admin-Go.exe" "$PACKAGE_DIR/BalanzaPro-Administrativo.exe"

echo "=== 5. Copiando Backend Laravel ==="
for dir in app bootstrap config database resources routes vendor; do
    echo "  Copiando backend/$dir ..."
    cp -r "$REPO_DIR/$dir" "$PACKAGE_DIR/backend/"
done
mkdir -p "$PACKAGE_DIR/backend/public"
cp "$REPO_DIR/public/index.php" "$PACKAGE_DIR/backend/public/"
cp "$REPO_DIR/public/robots.txt" "$PACKAGE_DIR/backend/public/" 2>/dev/null || true
cp "$REPO_DIR/public/favicon.ico" "$PACKAGE_DIR/backend/public/" 2>/dev/null || true
cp "$REPO_DIR/public/.htaccess" "$PACKAGE_DIR/backend/public/" 2>/dev/null || true
rm -rf "$PACKAGE_DIR/backend/public/storage"
mkdir -p "$PACKAGE_DIR/backend/public/storage/products"
mkdir -p "$PACKAGE_DIR/backend/storage/app/public/products"
mkdir -p "$PACKAGE_DIR/backend/storage/framework/cache"
mkdir -p "$PACKAGE_DIR/backend/storage/framework/sessions"
mkdir -p "$PACKAGE_DIR/backend/storage/framework/views"
mkdir -p "$PACKAGE_DIR/backend/storage/logs"
cp "$REPO_DIR/artisan" "$PACKAGE_DIR/backend/"
cp "$REPO_DIR/server.php" "$PACKAGE_DIR/backend/"
cp "$REPO_DIR/composer.json" "$PACKAGE_DIR/backend/"

echo "=== 6. Configurando .env para SQLite local offline ==="
cat << 'EOF' > "$PACKAGE_DIR/backend/.env"
APP_NAME="BalanzaPro"
APP_ENV=local
APP_KEY=base64:EhyPsmUf21n4b1bH2f9y8zR1o0p7q4t6w9x1z4c7v0m=
APP_DEBUG=false
APP_URL=http://localhost:8787

APP_BUSINESS_TIMEZONE=America/Caracas
APP_ALLOWED_ORIGINS_FOR_CSRF=http://localhost,http://localhost:8787,http://localhost:8788,http://localhost:8789,http://localhost:8798,http://localhost:8799,http://127.0.0.1,http://127.0.0.1:8787,http://127.0.0.1:8788,http://127.0.0.1:8789,http://127.0.0.1:8798,http://127.0.0.1:8799

APP_LOCALE=es
APP_FALLBACK_LOCALE=es

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=error

DB_CONNECTION=sqlite
DB_DATABASE=database/database.sqlite
DB_FOREIGN_KEYS=true

SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
EOF

echo "=== 7. Copiando Base de Datos SQLite con Superleopard (1,988 productos) ==="
cp "$REPO_DIR/storage/app/offline_packages/superleopard/database.sqlite" "$PACKAGE_DIR/backend/database/database.sqlite"

echo "=== 8. Copiando Fotos Locales (1,902 fotos) ==="
cp -r "$REPO_DIR/storage/app/offline_packages/superleopard/images/"* "$PACKAGE_DIR/backend/public/storage/products/"

echo "=== 8b. Copiando Instalador Microsoft WebView2 Bootstrapper ==="
if [ -f "/tmp/MicrosoftEdgeWebview2Setup.exe" ]; then
    cp "/tmp/MicrosoftEdgeWebview2Setup.exe" "$PACKAGE_DIR/MicrosoftEdgeWebview2Setup.exe"
fi

echo "=== 9. Creando Scripts de Inicio (.bat) ==="
cat << 'EOF' > "$PACKAGE_DIR/Iniciar-POS.bat"
@echo off
title BalanzaPro POS
cd /d "%~dp0"

if exist "%ProgramFiles(x86)%\Microsoft\EdgeWebView\Application" goto :pos_wv2_ok
if exist "%ProgramFiles%\Microsoft\EdgeWebView\Application" goto :pos_wv2_ok
if exist "%LocalAppData%\Microsoft\EdgeWebView\Application" goto :pos_wv2_ok

reg query "HKLM\SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :pos_wv2_ok
reg query "HKLM\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :pos_wv2_ok
reg query "HKCU\Software\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :pos_wv2_ok

if exist "%~dp0MicrosoftEdgeWebview2Setup.exe" (
    echo Instalando componente necesario de Windows (WebView2)...
    start /wait "" "%~dp0MicrosoftEdgeWebview2Setup.exe" /silent /install
)
:pos_wv2_ok

tasklist /FI "IMAGENAME eq MotorLocal.exe" 2>NUL | find /I /N "MotorLocal.exe">NUL
if "%ERRORLEVEL%"=="1" (
    echo Iniciando Motor Local en segundo plano...
    start "" "%~dp0MotorLocal.exe" -php="%~dp0php\php.exe" -backend="%~dp0backend" -port=8787 -backend-port=8788 -offline=true
    timeout /t 1 /nobreak >nul
)

echo Abriendo BalanzaPro POS...
start "" "%~dp0BalanzaPro-POS.exe"
exit
EOF

cat << 'EOF' > "$PACKAGE_DIR/Iniciar-Administrativo.bat"
@echo off
title BalanzaPro Administrativo
cd /d "%~dp0"

if exist "%ProgramFiles(x86)%\Microsoft\EdgeWebView\Application" goto :admin_wv2_ok
if exist "%ProgramFiles%\Microsoft\EdgeWebView\Application" goto :admin_wv2_ok
if exist "%LocalAppData%\Microsoft\EdgeWebView\Application" goto :admin_wv2_ok

reg query "HKLM\SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :admin_wv2_ok
reg query "HKLM\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :admin_wv2_ok
reg query "HKCU\Software\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :admin_wv2_ok

if exist "%~dp0MicrosoftEdgeWebview2Setup.exe" (
    echo Instalando componente necesario de Windows (WebView2)...
    start /wait "" "%~dp0MicrosoftEdgeWebview2Setup.exe" /silent /install
)
:admin_wv2_ok

tasklist /FI "IMAGENAME eq MotorLocal.exe" 2>NUL | find /I /N "MotorLocal.exe">NUL
if "%ERRORLEVEL%"=="1" (
    echo Iniciando Motor Local en segundo plano...
    start "" "%~dp0MotorLocal.exe" -php="%~dp0php\php.exe" -backend="%~dp0backend" -port=8787 -backend-port=8788 -offline=true
    timeout /t 1 /nobreak >nul
)

echo Abriendo BalanzaPro Administrativo...
start "" "%~dp0BalanzaPro-Administrativo.exe"
exit
EOF

cat << 'EOF' > "$PACKAGE_DIR/Instalar-Accesos-Directos.bat"
@echo off
title Instalar Accesos Directos
cd /d "%~dp0"

echo ==============================================================
echo  Instalando accesos directos en tu Escritorio de Windows...
echo ==============================================================

if exist "%ProgramFiles(x86)%\Microsoft\EdgeWebView\Application" goto :shortcuts_wv2_ok
if exist "%ProgramFiles%\Microsoft\EdgeWebView\Application" goto :shortcuts_wv2_ok
if exist "%LocalAppData%\Microsoft\EdgeWebView\Application" goto :shortcuts_wv2_ok

reg query "HKLM\SOFTWARE\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :shortcuts_wv2_ok
reg query "HKLM\SOFTWARE\WOW6432Node\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :shortcuts_wv2_ok
reg query "HKCU\Software\Microsoft\EdgeUpdate\Clients\{F3017226-9E47-4475-A062-8A96F24817F4}" /v pv >nul 2>&1 && goto :shortcuts_wv2_ok

if exist "%~dp0MicrosoftEdgeWebview2Setup.exe" (
    echo.
    echo  Instalando Microsoft Edge WebView2 Runtime para Windows...
    start /wait "" "%~dp0MicrosoftEdgeWebview2Setup.exe" /silent /install
)
:shortcuts_wv2_ok

powershell -NoProfile -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut([Environment]::GetFolderPath('Desktop') + '\BalanzaPro POS.lnk'); $s.TargetPath = '%~dp0BalanzaPro-POS.exe'; $s.WorkingDirectory = '%~dp0'; $s.IconLocation = '%~dp0BalanzaPro-POS.exe,0'; $s.Save()"

powershell -NoProfile -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut([Environment]::GetFolderPath('Desktop') + '\BalanzaPro Administrativo.lnk'); $s.TargetPath = '%~dp0BalanzaPro-Administrativo.exe'; $s.WorkingDirectory = '%~dp0'; $s.IconLocation = '%~dp0BalanzaPro-Administrativo.exe,0'; $s.Save()"

echo.
echo ==============================================================
echo Listo! Se crearon los accesos directos en tu Escritorio:
echo   - BalanzaPro POS
echo   - BalanzaPro Administrativo
echo ==============================================================
echo.
pause
EOF

cat << 'EOF' > "$PACKAGE_DIR/Instalar-Servicio-Inicio.bat"
@echo off
title Configurar Inicio Automatico - BalanzaPro
cd /d "%~dp0"

echo ==============================================================
echo  Configurando BalanzaPro Motor Local en inicio de Windows
echo ==============================================================

reg add "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "BalanzaProMotorLocal" /t REG_SZ /d "\"%~dp0MotorLocal.exe\"" /f >nul 2>&1
schtasks /Create /TN "BalanzaProMotorLocal" /TR "\"%~dp0MotorLocal.exe\"" /SC ONLOGON /RL HIGHEST /F >nul 2>&1

echo.
echo Iniciando Motor Local en segundo plano ahora...
start "" "%~dp0MotorLocal.exe"

echo.
echo ==============================================================
echo Listo! BalanzaPro ahora iniciara siempre de forma automatica
echo con Windows. Podras abrir la app de inmediato sin demoras.
echo ==============================================================
echo.
pause
EOF

cat << 'EOF' > "$PACKAGE_DIR/Desinstalar-Servicio-Inicio.bat"
@echo off
title Quitar Inicio Automatico - BalanzaPro
cd /d "%~dp0"

echo ==============================================================
echo  Quitando inicio automatico de BalanzaPro Motor Local
echo ==============================================================

reg delete "HKCU\Software\Microsoft\Windows\CurrentVersion\Run" /v "BalanzaProMotorLocal" /f >nul 2>&1
schtasks /Delete /TN "BalanzaProMotorLocal" /F >nul 2>&1
taskkill /F /IM MotorLocal.exe /IM php.exe >nul 2>&1

echo Listo. El servicio en segundo plano se detuvo y se quito del inicio.
pause
EOF

cat << 'EOF' > "$PACKAGE_DIR/LEEME-INSTRUCCIONES.txt"
==================================================================
           BALANZAPRO - PAQUETE COMPLETO CON GO TODO-EN-UNO
==================================================================

Este paquete contiene TODO lo necesario para instalar y usar el
sistema en una computadora NUEVA con Windows desde CERO (sin instalar
PHP ni Node ni nada adicional).

INSTRUCCIONES DE USO:
1. Copia o extrae esta carpeta completa en tu computadora
   (Recomendado: C:\BalanzaPro)

2. Haz doble clic en:
   "Instalar-Accesos-Directos.bat"
   (Esto creara los iconos en tu Escritorio).

3. Para abrir el sistema:
   - Haz doble clic en el acceso directo del Escritorio:
     "BalanzaPro POS"  o  "BalanzaPro Administrativo"
   - O haz doble clic en "Iniciar-POS.bat" o "Iniciar-Administrativo.bat"

ACCESO DESDE OTRAS COMPUTADORAS (RED LOCAL / LAN):
Si tienes otras computadoras en la misma tienda o red Wi-Fi:
1. Abre la consola de MotorLocal.exe para ver tu direccion IP
   (ejemplo: http://192.168.1.50:8787)
2. En las otras computadoras abre Google Chrome en esa direccion
   y podras facturar directamente contra esta maquina.

USUARIOS REGISTRADOS:
- superleopard@gmail.com
==================================================================
EOF

echo "=== 10. Empaquetando en ZIP final: $OUTPUT_ZIP ==="
mkdir -p "$(dirname "$OUTPUT_ZIP")"
cd /tmp/windows-packager
zip -r -q "$OUTPUT_ZIP" "BalanzaPro-Go-Completo"

echo "=== Listo! Paquete completo con Go generado en: $OUTPUT_ZIP ==="
ls -lh "$OUTPUT_ZIP"
