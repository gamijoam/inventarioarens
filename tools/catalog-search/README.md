# Catalog Search en Go (`tools/catalog-search`)

Servidor de búsqueda ultrarrápida de catálogo en memoria (*In-Memory Search Server*) en **Go puro (sin CGO)**.

---

## ⚡ Rendimiento

- **Tiempo de respuesta**: **< 0.5 milisegundos** por búsqueda.
- **Consumo de memoria RAM**: **< 8 MB** para catálogos de 20.000 productos.
- **Capacidades**:
  - Búsqueda exacta por código de barra en **$O(1)$**.
  - Búsqueda por prefijo de SKU y nombres.
  - Búsqueda *full-text* multipalabra tolerante a mayúsculas y acentos (`á` $\rightarrow$ `a`, `ñ` $\rightarrow$ `n`).
  - Aislamiento multi-tenant por `tenant_id`.

---

## 🔌 API HTTP (Puerto 18888)

### 1. `GET /health`
```json
{ "ok": true, "service": "catalog-search", "port": 18888, "count": 1250 }
```

### 2. `GET /search?q=aceite&tenant_id=1&limit=20`
```json
{
  "ok": true,
  "query": "aceite",
  "results": [
    {
      "product": {
        "id": 101,
        "sku": "ACE-20W50",
        "barcode": "7591234567890",
        "name": "Aceite para Motor 20W50",
        "price": 5.50,
        "stock": 42
      },
      "score": 10.0
    }
  ],
  "count": 1,
  "elapsed_us": 125
}
```

### 3. `GET /barcode?code=7591234567890&tenant_id=1`
Búsqueda instantánea directa por escáner de código de barras.

### 4. `POST /index`
Carga o actualización de lote de productos en memoria:
```json
[
  { "id": 101, "tenant_id": 1, "sku": "ACE-01", "name": "Aceite", "price": 5.50, "stock": 10 }
]
```

---

## 🧪 Pruebas Unitarias (TDD)

```bash
cd tools/catalog-search
go test -v ./...
```
