# Herramientas de Alto Rendimiento en Go y Optimizaciones de Fondo (2026-09-27)

> Documento técnico y operativo de los componentes nativos en Go desarrollados para **INVENTARIOARENS** y **Balanza Pro**, sustituyendo procesos PHP CLI de fondo, reduciendo drásticamente el consumo de memoria RAM y acelerando la respuesta a microsegundos.

---

## 1. Resumen Ejecutivo de la Arquitectura

Tradicionalmente, las tareas de fondo del Motor Local en Windows y del VPS corrían mediante procesos CLI de Laravel (`php artisan`). Aunque funcionales, cada proceso en bucle continuo consumía entre 60 y 85 MB de RAM, con riesgos de fugas de memoria (*memory leaks*) y latencias de arranque de ~1.5 segundos.

Se implementó una suite de **9 herramientas nativas en Go puro (sin CGO)**, desarrolladas bajo **TDD estricto (Red-Green-Refactor)**, que se compilan como binarios estáticos tanto para Linux como para Windows (`.exe` de 64 bits):

| Herramienta | Ruta en Repo | Puerto | Consumo RAM en Reposo | Función Principal |
|---|---|---|---|---|
| **`sync-daemon`** | `tools/sync-daemon` | N/A | **< 10 MB** (vs ~80 MB PHP) | Sincronización continua SQLite $\leftrightarrow$ Nube |
| **`printer-agent`**| `tools/printer-agent` | `17777` | **< 5.5 MB** (vs ~75 MB PHP) | Impresión térmica ESC/POS y apertura de gaveta |
| **`scale-agent`** | `tools/scale-agent` | `19999` | **< 5.1 MB** | Lectura de balanzas en tiempo real (SSE) |
| **`catalog-search`**| `tools/catalog-search`| `18888` | **1.4 MB** (en VPS) | Búsqueda y escaneo en memoria en < 0.1 ms |
| **`ws-hub`** | `tools/ws-hub` | `16666` | **1.6 MB** (en VPS) | Servidor WebSocket multitenant para eventos en tiempo real |
| **`image-optimizer`**| `tools/image-optimizer`| `14444` / CLI | **< 4.0 MB** | Redimensionamiento y optimización de imágenes |
| **`pdf-engine`** | `tools/pdf-engine` | `15555` / CLI | **< 3.0 MB** | Generación de facturas y tickets PDF en < 20 ms |
| **`barcode-engine`**| `tools/barcode-engine`| `13333` / CLI | **< 3.0 MB** | Códigos de barras (Code128, QR, EAN-13 Balanza) |
| **`watchdog`** | `tools/watchdog` | CLI / Daemon | **< 2.5 MB** | Supervisor y guardián autónomo de microservicios |

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

### 2.5 Servidor WebSocket Multitenant de Tiempo Real (`tools/ws-hub`)
- **Objetivo**: Proveer una capa de transporte bidireccional en tiempo real para eventos del sistema (actualización de tasas de cambio BCV/Paralelo, cambios de stock de inventario, alertas de caja y notificaciones de ventas) hacia el frontend web y los clientes Electron POS / Administrativo sin sobrecargar PHP ni recurrir a servicios externos de pago (como Pusher).
- **Arquitectura Multi-Tenant**:
  - Un único proceso en Go atiende todas las conexiones en `127.0.0.1:16666`.
  - **Aislamiento por canales**: El cliente se conecta especificando canales de interés en la URL (`ws://host:16666/ws?channels=tenant:4,global`) o enviando comandos JSON dinámicos (`{"action":"subscribe","channel":"tenant:4"}`).
  - **Consumo de memoria**: **1.6 MB de RAM** en el VPS.
  - **Endpoint REST `/publish`**: Permite al backend de Laravel o a cualquier microservicio emitir eventos hacia cualquier canal mediante una simple llamada HTTP POST interna:
    ```bash
    curl -X POST http://127.0.0.1:16666/publish \
      -H "Content-Type: application/json" \
      -d '{"channel":"tenant:4","event":"rate.updated","data":{"currency":"USD","rate":45.50}}'
    ```
  - Soporta autenticación opcional de publicación mediante clave secreta (`X-Auth-Key` / `WS_HUB_AUTH_KEY`).
  - Goroutines dedicadas por cliente (`readPump` y `writePump`) con ping/pong automático cada 30 segundos y prevención de caídas por desconexión abrupta.
- **Pruebas unitarias**: `pkg/hub`, `pkg/server` (100% PASS, cobertura de registro, unregister, suscripción dinámica, auth key y entrega).

### 2.6 Optimizador y Redimensionador de Imágenes (`tools/image-optimizer`)
- **Objetivo**: Reducir el consumo de almacenamiento y acelerar la carga del catálogo POS redimensionando fotos pesadas y optimizando la compresión JPEG/PNG.
- **Solución en Go**:
  - Algoritmo bilineal de alta calidad mediante `golang.org/x/image/draw`.
  - Reduce fotos de 5 a 10 MB a ~80 KB manteniendo nitidez (más de 85% de compresión).
  - Modos de ejecución: CLI unitario, escáner recursivo de directorios y microservicio HTTP en `:14444`.
  - Integración en Laravel mediante el helper [`App\Support\Media\ImageOptimizer`](file:///opt/inventarioarens-cloud/app/Support/Media/ImageOptimizer.php).
- **Pruebas unitarias**: `pkg/optimizer` (100% PASS) y `tests/Unit/Support/ImageOptimizerTest.php` (100% PASS).

### 2.7 Motor Ultrarrápido de Tickets y Facturas PDF (`tools/pdf-engine`)
- **Objetivo**: Generar tickets térmicos (58/80mm) y facturas de venta tamaño carta en PDF en milisegundos sin la lentitud ni el consumo de memoria de DomPDF en PHP.
- **Solución en Go**:
  - Generación vectorial pura mediante `github.com/jung-kurt/gofpdf` (sin CGO, sin WebKit ni Chrome).
  - Tiempo de generación: **< 20 milisegundos** por documento.
  - Doble moneda integrada (USD y VES con desglose de tasa de cambio).
### 2.8 Generador de Códigos de Barras y QR (`tools/barcode-engine`)
- **Objetivo**: Generar etiquetas de códigos de barra (Code128), códigos QR y códigos EAN-13 especiales para balanzas de pesaje en charcuterías y carnicerías.
- **Solución en Go**:
  - Implementación vectorial y raster PNG sin dependencias de fuentes externas ni CGO.
  - Soporte de **EAN-13 de Balanza**: estructura estandarizada `20 <item: 5 dígitos> <peso en gramos: 5 dígitos> <checksum mod-10>`.
  - Servidor HTTP en el puerto `:13333` (`/barcode/code128`, `/barcode/qr`, `/barcode/scale-ean13`) y CLI ejecutable.
  - Integración en Laravel mediante el helper [`App\Support\Barcode\BarcodeEngine`](file:///opt/inventarioarens-cloud/app/Support/Barcode/BarcodeEngine.php).
- **Pruebas unitarias**: `pkg/barcode` (100% PASS) y `tests/Unit/Support/BarcodeEngineTest.php` (100% PASS).

### 2.9 Guardián y Supervisor Autónomo (`tools/watchdog`)
- **Objetivo**: Monitorear continuamente los microservicios y agentes en segundo plano (Go y PHP), verificando sus endpoints de salud (`/health`) y ejecutando comandos de auto-recuperación si fallan reiteradamente.
- **Solución en Go**:
  - Comprobación concurrente de salud mediante goroutines con backoff y umbral configurable de fallos consecutivos (`failureThreshold`).
  - Capacidad de reiniciar servicios caídos (`systemctl restart ...` en Linux o `net start ...` en Windows) sin intervención humana.
  - Modo CLI y daemon en segundo plano (`watchdog -config=... -daemon`).
- **Pruebas unitarias**: `pkg/monitor` (100% PASS).

### 2.10 Emisión de Eventos en Tiempo Real (Laravel $\rightarrow$ WsHub)
- Helper centralizado [`App\Support\Realtime\WsHub`](file:///opt/inventarioarens-cloud/app/Support/Realtime/WsHub.php) con métodos `publish()` y `publishTenant()`.
- Disparo automático de eventos:
  - `rate.updated`: Al activar una nueva tasa de cambio (`ExchangeRateActivationService`).
  - `cash_register.opened` y `cash_register.closed`: Apertura y cierre de caja en POS (`CashRegisterService`).
  - `pos.order.paid` y `pos.order.pending`: Facturación y retención de tickets en el POS (`PosCheckoutService`).
- Cobertura de tests: [`tests/Feature/Realtime/WsHubEventBroadcastingTest.php`](file:///opt/inventarioarens-cloud/tests/Feature/Realtime/WsHubEventBroadcastingTest.php) (100% PASS).

### 2.11 Clientes Frontend en TypeScript (SPA / Electron)
- **`frontend/src/lib/wsHub.ts`**: Cliente WebSocket ligero con auto-reconexión exponencial, suscripción dinámica a canales y listener tipado para refresco de interfaz reactiva. Cobertura: [`frontend/src/lib/__tests__/wsHub.test.ts`](file:///opt/inventarioarens-cloud/frontend/src/lib/__tests__/wsHub.test.ts) (100% PASS).
- **`frontend/src/lib/catalogSearch.ts`**: Cliente de búsqueda instantánea contra el motor en memoria (`:18888`) con fallback defensivo al API de Laravel en caso de indisponibilidad. Cobertura: [`frontend/src/lib/__tests__/catalogSearch.test.ts`](file:///opt/inventarioarens-cloud/frontend/src/lib/__tests__/catalogSearch.test.ts) (100% PASS).

---

## 3. Integración en Windows y en el VPS

### 3.1 Empaquetado del Motor Local de Windows
- **`scripts/build-go-tools.sh`**: Compila las 9 herramientas en paralelo para Linux y Windows (`.exe` estáticos sin dependencias externas).
- **`scripts/stage-local-motor.cjs`**: Copia automáticamente los binarios `.exe` compilados dentro de la carpeta `tools/` de la distribución del Motor Local.
- **`scripts/install-local-motor.ps1`**: Registra los servicios de Windows mediante WinSW:
  - `SistemaInventarioBackend`: FrankenPHP (servidor web multi-hilo Caddy + PHP ZTS con OPcache en puerto `8787`).
  - `SistemaInventarioPrinter`: `printer-agent.exe` (puerto `17777`).
  - `SistemaInventarioSync`: `sync-daemon.exe`.
  - `SistemaInventarioWsHub`: `ws-hub.exe` (puerto `16666`).
  - Mantiene compatibilidad total hacia atrás: si los binarios no estuvieran presentes, realiza fallback automático a `php artisan`.

### 3.2 Servicios Activos en el VPS Linux
Tanto el buscador de catálogo como el servidor de WebSockets corren como servicios permanentes de systemd en el VPS:
- **`balanzapro-catalog-search.service`**:
  - Puerto: `127.0.0.1:18888`
  - Estado: `active (running)` — Consumo: **1.4 MB RAM**
  - Control: `systemctl status balanzapro-catalog-search.service`
- **`balanzapro-ws-hub.service`**:
  - Puerto: `127.0.0.1:16666`
  - Estado: `active (running)` — Consumo: **1.6 MB RAM**
  - Control: `systemctl status balanzapro-ws-hub.service`
