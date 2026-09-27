# Scale Agent en Go (`tools/scale-agent`)

Agente local de lectura de balanzas y básculas electrónicas (RS-232 / USB / COM) en **Go puro (sin CGO)** diseñado especialmente para **BalanzaPro**.

---

## ⚖️ Características

- **Consumo de Memoria**: **< 5 MB de RAM**.
- **Frecuencia de Muestreo**: Alta frecuencia (20-60 Hz) con emisión en tiempo real vía Server-Sent Events (SSE).
- **Protocolos Soportados**:
  - Genérico continuo ASCII (`ST,GS,+  1.250kg`, etc.)
  - Torrey (`01.345\r`, `P03.450\r`)
  - Modo Simulador (Mock) para pruebas automáticas y demos en POS sin hardware físico conectado.

---

## 🔌 API HTTP (Puerto 19999)

### 1. `GET /health`
```json
{
  "ok": true,
  "service": "balanzapro-scale-agent",
  "port": 19999
}
```

### 2. `GET /weight`
Devuelve la última lectura disponible:
```json
{
  "ok": true,
  "weight": 1.250,
  "unit": "kg",
  "is_stable": true,
  "raw": "ST,GS,+  1.250kg",
  "timestamp": "2026-09-27T18:19:35Z"
}
```

### 3. `GET /stream` (Server-Sent Events)
Stream en tiempo real para suscripción directa desde el carrito del POS en React / Electron:
```
data: {"weight":1.25,"unit":"kg","is_stable":true,"raw":"ST,GS,+  1.250kg","timestamp":"..."}

data: {"weight":1.25,"unit":"kg","is_stable":true,"raw":"ST,GS,+  1.250kg","timestamp":"..."}
```

---

## 🧪 Pruebas Unitarias (TDD)

```bash
cd tools/scale-agent
go test -v ./...
```
