# Herramientas de Alto Rendimiento en Go y Optimizaciones de Fondo (2026-09-27)

> Documento técnico y operativo de los componentes nativos en Go desarrollados para **INVENTARIOARENS** y **Balanza Pro**, sustituyendo procesos PHP CLI de fondo, reduciendo drásticamente el consumo de memoria RAM y acelerando la respuesta a microsegundos.

---

## 1. Resumen Ejecutivo de la Arquitectura

Tradicionalmente, las tareas de fondo del Motor Local en Windows y del VPS corrían mediante procesos CLI de Laravel (`php artisan`). Aunque funcionales, cada proceso en bucle continuo consumía entre 60 y 85 MB de RAM, con riesgos de fugas de memoria (*memory leaks*) y latencias de arranque de ~1.5 segundos.

Se implementó una suite de **4 herramientas nativas en Go puro (sin CGO)**, desarrolladas bajo **TDD estricto (Red-Green-Refactor)**, que se compilan como binarios estáticos tanto para Linux como para Windows (`.exe` de 64 bits):

| Herramienta | Ruta en Repo | Puerto | Consumo RAM en Reposo | Función Principal |
|---|---|---|---|---|
| **`sync-daemon`** | `tools/sync-daemon` | N/A | **< 10 MB** (vs ~80 MB PHP) | Sincronización continua SQLite $\leftrightarrow$ Nube |
| **`printer-agent`**| `tools/printer-agent` | `17777` | **< 5.5 MB** (vs ~75 MB PHP) | Impresión térmica ESC/POS y apertura de gaveta |
| **`scale-agent`** | `tools/scale-agent` | `19999` | **< 5.1 MB** | Lectura de balanzas en tiempo real (SSE) |
| **`catalog-search`**| `tools/catalog-search`| `18888` | **1.4 MB** (en VPS) | Búsqueda y escaneo en memoria en < 0.1 ms |

---

## 2. Detalle de Herramientas Implementadas

### 2.1 Daemon de Sincronización Local (`tools/sync-daemon`)
- **Problema previo**: `php artisan sync:daemon-all --interval=15` consumía ~80 MB y ejecutaba el bootstrap completo de Laravel en cada iteración de sondeo.
- **Solución en Go**:
  - Lectura y monitoreo dinámico de `storage/app/sync-worker/sync-config.json`.
  - Conexión a SQLite nativa mediante `modernc.org/sqlite` (sin CGO) en modo WAL con `busy_timeout=15000`.
  - Cliente HTTP/2 con keep-alive contra la nube (`/api/sync/events/push`, `/pull`, `/ack`).
  - **Desacoplamiento óptimo**: Go realiza el 99% del trabajo de red y sondeo. Solo cuando se descargan eventos pendientes en `sync_inbox`, ejecuta puntualmente `php artisan sync:apply-inbox <slug>` para aplicar las reglas de negocio de Laravel con OPcache (< 100 ms).
- **Pruebas unitarias**: `pkg/config`, `pkg/client`, `pkg/storage`, `pkg/worker` (100% PASS).

### 2.2 Agente de Impresión Térmica ESC/POS (`tools/printer-agent`)
- **Problema previo**: `php artisan printer:serve --port=17777` corría un bucle monohilo en PHP con sockets bloqueantes.
- **Solución en Go**:
  - Servidor HTTP nativo en el puerto `17777` con CORS completo (soporta preflight `OPTIONS`).
  - Generador de secuencias ESC/POS estándar para apertura de gaveta de dinero (`\x1B\x70\x00\x19\xFA`) y corte completo de papel (`\x1D\x56\x00`).
  - Sanitizador ASCII y traductor de acentos para compatibilidad universal con cualquier impresora térmica china o genérica sin soporte UTF-8.
  - Formateador de tickets de venta y Reportes Z (32 columnas para 58mm y 48 columnas para 80mm).
  - Soporte de salida digital: guarda el PDF decodificado en Base64 o el ticket `.txt` de respaldo en la carpeta configurada.
- **Pruebas unitarias**: `pkg/escpos`, `pkg/format`, `pkg/printer`, `pkg/server` (100% PASS).

### 2.3 Lector de Balanzas y Básculas Serial/USB (`tools/scale-agent`)
- **Objetivo**: Diseñado para **Balanza Pro** para productos pesables a granel (charcuterías, carnicerías, víveres).
- **Solución en Go**:
  - Lee a alta frecuencia (20 a 60 Hz) puertos COM/RS-232 o USB.
  - Parsers integrados: genérico continuo ASCII (`ST,GS,+  1.250kg`) y Torrey (`01.345\r`).
  - Modo simulador (`MockReader`) para demos y pruebas automáticas en el POS sin hardware físico conectado.
  - Endpoint `GET /weight` (último peso instantáneo) y `GET /stream` mediante **Server-Sent Events (SSE)** para que el carrito del POS en React/Electron actualice el peso en vivo.
- **Pruebas unitarias**: `pkg/protocol`, `pkg/reader`, `pkg/server` (100% PASS).

### 2.4 Buscador de Catálogo en Memoria (`tools/catalog-search`)
- **Objetivo**: Búsqueda instantánea de productos y escaneo de códigos de barra en < 1 milisegundo.
- **Arquitectura Multi-Tenant**:
  - **Un solo servicio atiende a todas las empresas**: No se requiere un proceso por empresa. En memoria mantiene tablas hash indexadas por `tenant_id:barcode`, `tenant_id:sku` y un índice invertido de tokens.
  - Consumo de memoria: **1.4 MB de RAM** en el VPS.
  - Búsqueda exacta por código de barra en **$O(1)$**.
  - Búsqueda *full-text* multipalabra tolerante a acentos y mayúsculas (`TÉFLON` $\rightarrow$ `teflon`).
  - **Prueba en vivo en VPS**: Tiempo de respuesta medido de **55 a 94 microsegundos** (0.05 a 0.09 ms).
- **Pruebas unitarias**: `pkg/index`, `pkg/server` (100% PASS).

---

## 3. Integración en Windows y en el VPS

### 3.1 Empaquetado del Motor Local de Windows
- **`scripts/build-go-tools.sh`**: Compila las 4 herramientas en paralelo para Linux y Windows (`.exe` estáticos sin dependencias externas).
- **`scripts/stage-local-motor.cjs`**: Copia automáticamente los binarios `.exe` compilados dentro de la carpeta `tools/` de la distribución del Motor Local.
- **`scripts/install-local-motor.ps1`**: Registra los servicios de Windows mediante WinSW:
  - `SistemaInventarioBackend`: FrankenPHP (servidor web multi-hilo Caddy + PHP ZTS con OPcache en puerto `8787`).
  - `SistemaInventarioPrinter`: `printer-agent.exe` (puerto `17777`).
  - `SistemaInventarioSync`: `sync-daemon.exe`.
  - Mantiene compatibilidad total hacia atrás: si los binarios no estuvieran presentes, realiza fallback automático a `php artisan`.

### 3.2 Servicio en el VPS Linux (Balanza Pro)
- El buscador de catálogo corre como servicio permanente de systemd:
  - Archivo: `/etc/systemd/system/balanzapro-catalog-search.service`
  - Estado: `active (running)`, consumo de 1.4 MB de RAM, puerto `127.0.0.1:18888`.
  - Comando de control: `systemctl status balanzapro-catalog-search.service`.
