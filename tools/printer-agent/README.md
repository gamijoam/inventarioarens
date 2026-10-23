# Printer Agent en Go (`tools/printer-agent`)

Agente local de impresión térmica y digital de alto rendimiento desarrollado en **Go puro (sin CGO)**.

Sustituye al comando `php artisan printer:serve --port=17777` en el Motor Local de Windows y entornos de desarrollo/producción.

---

## 🚀 Ventajas sobre `php artisan printer:serve`

| Métrica | `php artisan printer:serve` (PHP CLI) | `printer-agent` (Go) |
|---|---|---|
| **Consumo de Memoria RAM** | ~60 - 80 MB | **< 6 MB** |
| **Tiempo de Arranque** | ~800 - 1500 ms (bootstrap de Laravel) | **< 10 ms** |
| **Concurrencia HTTP** | Bucle single-threaded con sleep | Concurrente nativo con goroutines |
| **Dependencias en Runtime** | Requiere PHP CLI + extensiones | **Cero dependencias** (binario estático) |
| **CORS** | Cabeceras manuales en socket crudo | Manejo robusto RFC nativo |

---

## 🔌 API HTTP (Puerto 17777)

### 1. `GET /health`
Verificación de estado para health checks de Windows Service, Electron y Centro Técnico Local.

**Respuesta (200 OK):**
```json
{
  "ok": true,
  "service": "inventarioarens-printer-agent",
  "port": 17777
}
```

### 2. `POST /print`
Impresión de tickets de venta o Reporte Z (soporta salida `thermal` y `digital`).

**Headers:**
`Content-Type: application/json`

**Cuerpo (Ejemplo Térmico):**
```json
{
  "output": "thermal",
  "station": {
    "printer_type": "windows_printer",
    "printer_name": "POS-58",
    "network_host": null,
    "network_port": 9100
  },
  "payload": {
    "tenant": { "name": "Mi Tienda", "slug": "mi-tienda" },
    "pos_order": { "id": 105, "paid_at": "2026-09-27T14:30:00Z" },
    "totals": { "total_base_amount": 10.00 },
    "items": []
  }
}
```

### 3. `OPTIONS /*`
Preflight CORS automático (204 No Content).

---

## 🧪 Pruebas Unitarias (TDD)

```bash
cd tools/printer-agent
go test -v ./...
```

---

## 🛠️ Compilación

### Para Linux:
```bash
go build -ldflags="-s -w" -o bin/printer-agent ./cmd/printer-agent
```

### Para Windows (`.exe` estático sin CGO):
```bash
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/printer-agent.exe ./cmd/printer-agent
```

---

## 🪟 Integración como Servicio de Windows (`SistemaInventarioPrinter`)

En `scripts/install-local-motor.ps1` o WinSW:
```xml
<service>
  <id>SistemaInventarioPrinter</id>
  <name>Sistema de Inventario - Impresion</name>
  <description>Agente local de impresion termica en Go</description>
  <executable>%BASE%\runtime\printer-agent\printer-agent.exe</executable>
  <arguments>-port 17777 -bind 127.0.0.1</arguments>
  <logmode>roll</logmode>
</service>
```

## Agente independiente por usuario en BalanzaPro LAN

Las cajas que usan el POS de la PC principal desde un navegador pueden instalar
el agente independiente publicado como `BalanzaPro-Impresor-Setup-<version>.exe`.
Se inicia al entrar a Windows y escucha solo en `127.0.0.1:17777`; no instala el
Motor ni una base de datos. El perfil de impresión del servidor debe guardar el
nombre exacto de la impresora Windows de esa estación.

El instalador independiente no debe usarse en una PC donde ya corre el agente
del Motor Local, porque ambos usan el puerto `17777`.
