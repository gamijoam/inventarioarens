# API de transporte de sincronizacion

## Objetivo

Agregar el primer contrato HTTP para mover eventos entre local y nube sin aplicar todavia reglas de negocio automaticamente. Esta fase prepara el canal confiable para el futuro worker local.

## Endpoints

Todas las rutas requieren:

- `Authorization: Bearer <token>`
- `X-Tenant: <slug o id de empresa>`

### Registrar nodo

`POST /api/sync/nodes`

Registra o actualiza una instalacion local, nodo nube o worker.

Payload:

```json
{
  "code": "LOCAL-CCS-01",
  "name": "Caja local Caracas",
  "type": "local",
  "status": "active",
  "metadata": {
    "app": "desktop",
    "version": "1.0.0"
  }
}
```

### Subir eventos

`POST /api/sync/events/push`

Recibe eventos desde otro nodo y los guarda en `sync_inbox`. Si el mismo `event_uuid` llega dos veces, no se duplica.

Payload:

```json
{
  "origin_node_code": "LOCAL-CCS-01",
  "events": [
    {
      "event_uuid": "11111111-1111-1111-1111-111111111111",
      "event_type": "pos.order.paid",
      "aggregate_type": "pos_order",
      "aggregate_id": 10,
      "payload": {
        "order_id": 10,
        "total_base_amount": "20.0000"
      }
    }
  ]
}
```

Respuesta:

```json
{
  "data": {
    "received": 1,
    "duplicated": 0
  }
}
```

### Descargar eventos

`GET /api/sync/events/pull?node_code=LOCAL-CCS-01&limit=50`

Entrega eventos `pending` desde `sync_outbox` para el tenant actual. No devuelve eventos originados por el mismo nodo.

### Confirmar evento

`POST /api/sync/events/{event_uuid}/ack`

Marca un evento como aplicado o fallido.

Payload aplicado:

```json
{
  "node_code": "LOCAL-CCS-01",
  "status": "applied"
}
```

Payload fallido:

```json
{
  "node_code": "LOCAL-CCS-01",
  "status": "failed",
  "error": "No se pudo aplicar el precio porque el producto no existe localmente."
}
```

### Estado

`GET /api/sync/status?node_code=LOCAL-CCS-01`

Devuelve conteos de nodos, outbox, inbox y estados del nodo.

## Alcance actual

Este API solo mueve eventos y registra estado de entrega. La aplicacion concreta de cambios como precios, tasas, productos o permisos se implementara por fases para no mezclar reglas de negocio.

## Pruebas ejecutadas

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncSchemaTest.php tests\Feature\Sync\SyncApiTest.php
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\POS\PosCheckoutApiTest.php tests\Feature\CashRegister\CashRegisterApiTest.php
```

Resultado:

- Sincronizacion: 6 pruebas pasadas, 38 aserciones.
- POS y Caja relacionados: 24 pruebas pasadas, 168 aserciones.
