# Historial de clientes en POS WPF

## Objetivo

Mostrar al cajero el historial reciente del cliente antes de asociarlo a una venta POS.

## Implementado

- Se agrego `include=pos_history` en `GET /api/customers/{customer}`.
- El historial se calcula por empresa y no mezcla informacion de otros tenants.
- El selector de clientes de WPF ahora muestra:
  - compras totales;
  - compras pagadas;
  - ordenes pendientes;
  - total historico en USD;
  - saldo pendiente estimado;
  - ultimas 5 ordenes POS.
- La busqueda inicial de clientes sigue ligera. El historial se consulta solo al seleccionar un cliente.

## API

```http
GET /api/customers/{customer}?include=pos_history
```

Respuesta adicional:

```json
{
  "data": {
    "pos_history": {
      "total_orders": 2,
      "paid_orders": 1,
      "open_orders": 1,
      "total_base_amount": 130,
      "paid_base_amount": 100,
      "balance_base_amount": 30,
      "recent_orders": []
    }
  }
}
```

## Pruebas

- `tests/Feature/Customers/CustomerApiTest.php` valida que el historial POS se pueda incluir en el detalle del cliente.
- La prueba confirma que no se mezclen ordenes de otra empresa.

