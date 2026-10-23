# Impresoras por PC en el POS BalanzaPro

El POS de la PC principal puede abrirse desde otras computadoras de la misma red. Para que cada caja imprima en la térmica conectada a su propia PC, el equipo cliente necesita el agente liviano **BalanzaPro Impresor Local**. Este agente no instala Laravel, FrankenPHP, Motor Local, sincronización ni una base de datos.

## Preparar la PC principal

1. Instala Motor Local, BalanzaPro POS, Administrativo y Soporte Técnico.
2. En Soporte Técnico abre **Esta instalación** y activa **Servidor LAN**.
3. Reinicia BalanzaPro POS y déjalo abierto. El POS sirve la pantalla y envía las solicitudes al Motor que permanece en esa PC.
4. En PowerShell ejecuta `ipconfig` y anota la IPv4 de la red privada, por ejemplo `192.168.1.20`.
5. Si Windows Firewall bloquea la conexión, permite TCP `8791` solo para el perfil **Privado** y la subred local. Administrativo y Soporte usan `8792` y `8793` si también se necesitan desde navegador.

Como administrador en la PC principal, la regla para el POS se puede crear así:

```powershell
New-NetFirewallRule `
  -DisplayName "BalanzaPro POS LAN" `
  -Direction Inbound `
  -Action Allow `
  -Protocol TCP `
  -LocalPort 8791 `
  -Profile Private `
  -RemoteAddress LocalSubnet
```

No abras los puertos `8787` ni `17777` al resto de la red. La API y el agente de la PC principal permanecen en loopback.

## Preparar cada PC de caja

1. Instala y prueba la impresora térmica en Windows y establécela como impresora predeterminada de esa PC en **Configuración → Bluetooth y dispositivos → Impresoras y escáneres**.
2. Instala `BalanzaPro-Impresor-Setup-<version>.exe` desde el release **BalanzaPro Impresor Local**. Se instala en el perfil del usuario, se inicia al entrar a Windows y no requiere instalar Motor Local.
3. Desde el navegador abre `http://IP-DE-LA-PC-PRINCIPAL:8791`, inicia sesión y permite el acceso a la red local si el navegador lo solicita.
4. En la configuración de impresión, asigna una estación a la caja para elegir su perfil de ticket. El POS LAN envía el trabajo al agente local y usa la impresora predeterminada de cada PC secundaria. Deja el conector de nube sin asignar a estas estaciones LAN.
5. Haz una venta de prueba y confirma que el ticket salga en la impresora de esa computadora.

El usuario de Windows debe mantener iniciada su sesión para imprimir, porque el agente corre en esa sesión y usa la impresora predeterminada de ese usuario. Cada navegador imprime directamente a `127.0.0.1:17777` de su propia PC; el trabajo de venta y el perfil de la estación siguen en el Motor de la PC principal.

## Descargas y compilación

El workflow `.github/workflows/release-balanzapro-printer-agent.yml` compila el instalador y publica el setup, el ejecutable portable y sus SHA-256 en un release separado (`printer-v<version>-balanzapro`). La versión inicial debe publicarse como candidata y probarse con una impresora real antes de marcarla estable.

## Alcance

- Instalar BalanzaPro POS como `.exe` en la PC secundaria no la conecta al Motor principal; los `.exe` Electron siguen usando el Motor local.
- El acceso compartido desde otras PCs se hace con navegador y Servidor LAN.
- El agente local solo se instala en las PCs que deben imprimir en su propia térmica. No se comparte SQLite ni se instala otro Motor en las cajas.
- Para instalar solo el agente en una PC ya administrada por Motor Local, no uses este instalador junto con el servicio de impresión del Motor: ambos usan el puerto local `17777`.
