# InventarioArens Sync Daemon (Go)

Daemon de sincronización local-nube de alto rendimiento escrito en **Go (Golang)** con **TDD**.

Sustituye al proceso en bucle continuo `php artisan sync:daemon-all` en el Motor Local de Windows y Linux, reduciendo el consumo de memoria de ~80 MB (PHP CLI) a **< 10 MB**, con **0.0% de CPU en reposo** y concurrencia nativa por empresa.

---

## 1. Características

- **Pure Go (Zero CGO)**: Usa `modernc.org/sqlite` para interactuar con SQLite (`inventario.sqlite`) sin depender de GCC, MinGW ni DLLs externas de C++.
- **HTTP/2 Connection Pooling**: Conexiones persistentes keep-alive contra la API de la nube (`https://app.balanzapro.com/api` o `https://app.miinventariofacil.com/api`), eliminando la sobrecarga de negociación TLS en cada ciclo.
- **Multitenancy y Tickers Independientes**: Respeta el intervalo configurado de cada empresa (`interval: 5s - 300s`) sin que un tenant lento bloquee a los demás.
- **Transaccionalidad Segura (WAL + Busy Timeout)**: Configurado con `busy_timeout=15000` y `journal_mode=WAL` para operar sin conflictos con el POS o FrankenPHP.
- **Desacoplamiento Limpio**:
  - Go se encarga del 99% del trabajo pesado y continuo (monitoreo de red, outbox polling, push, pull de eventos, ACKs).
  - Solo cuando hay eventos nuevos en el inbox, invoca puntualmente a `artisan sync:apply-inbox <slug>` para ejecutar las reglas de dominio en Laravel, el cual termina en milisegundos gracias a OPcache.

---

## 2. Estructura del Proyecto

```
tools/sync-daemon/
├── cmd/
│   └── sync-daemon/
│       └── main.go           # CLI y servicio del daemon
├── pkg/
│   ├── config/               # Parser de sync-config.json
│   │   ├── config.go
│   │   └── config_test.go    # Tests unitarios TDD
│   ├── client/               # Cliente HTTP de la nube (Push/Pull/Ack)
│   │   ├── client.go
│   │   └── client_test.go    # Tests unitarios con httptest.Server
│   ├── storage/              # Acceso SQLite nativo (outbox/inbox/tenants)
│   │   ├── storage.go
│   │   └── storage_test.go   # Tests unitarios con SQLite en memoria
│   └── worker/               # Orquestador del ciclo completo de sync
│       ├── worker.go
│       └── worker_test.go    # Tests de integración TDD
├── bin/
│   ├── sync-daemon           # Binario para Linux (10 MB)
│   └── sync-daemon.exe       # Binario para Windows (11 MB)
├── go.mod
└── go.sum
```

---

## 3. Pruebas Unitarias (TDD)

Para ejecutar la suite completa de pruebas unitarias:

```bash
cd tools/sync-daemon
go test -v ./...
```

Todas las pruebas se ejecutan contra servidores HTTP mock (`httptest.Server`) y bases de datos SQLite en memoria (`:memory:`), garantizando pruebas rápidas (menos de 0.1s en total) e independientes.

---

## 4. Compilación

### Para Linux:
```bash
cd tools/sync-daemon
go build -ldflags="-s -w" -o bin/sync-daemon ./cmd/sync-daemon
```

### Para Windows (Cross-compilation sin CGO):
```bash
cd tools/sync-daemon
GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/sync-daemon.exe ./cmd/sync-daemon
```

---

## 5. Integración con el Motor Local (WinSW en Windows)

En el servicio `SistemaInventarioSync` del Motor Local (`C:\ProgramData\InventarioArens\service\SistemaInventarioSync.xml`):

```xml
<service>
  <id>SistemaInventarioSync</id>
  <name>Sistema de Inventario - Sincronizacion</name>
  <description>Daemon de sincronizacion continua con la nube (Go)</description>
  <executable>%BASE%\runtime\sync-daemon\sync-daemon.exe</executable>
  <arguments>-config "%BASE%\storage\app\sync-worker\sync-config.json" -database "%BASE%\inventario.sqlite" -php "%BASE%\runtime\php\php.exe" -backend-root "%BASE%\backend"</arguments>
  <logmode>rotate</logmode>
</service>
```
