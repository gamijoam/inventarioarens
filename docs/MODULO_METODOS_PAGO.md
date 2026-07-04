# Módulo de métodos de pago

## Objetivo

Este módulo define cómo puede cobrar la empresa en POS y futuras pantallas de caja. La idea es que el usuario configure métodos como efectivo USD, pago móvil, transferencia, Zelle, punto de venta o financiadora, y que el POS solo muestre opciones válidas según la lista de precio usada.

## Modelo de uso

- Un método de pago pertenece a una empresa.
- Un método tiene un tipo operativo: `cash`, `card`, `mobile_payment`, `transfer`, `zelle`, `external_financing` u `other`.
- Un método puede aceptar solo `USD`, solo `VES` o ser `flexible`.
- Un método puede exigir referencia, por ejemplo Zelle, transferencia o pago móvil.
- Una lista de precio puede quedar abierta o restringida.
- Si la lista queda abierta, el POS mantiene el comportamiento tradicional.
- Si la lista queda restringida, el POS solo puede cobrar con los métodos asociados.

## Ejemplos prácticos

### Lista detal solo en divisas

- Lista: `DETAL USD`
- Métodos permitidos:
  - Efectivo USD
  - Zelle
  - Tarjeta internacional

Resultado: si el cajero intenta pagar en bolívares, backend rechaza el checkout.

### Lista flexible

- Lista: `DETAL FLEXIBLE`
- Métodos permitidos:
  - Efectivo USD
  - Pago móvil VES
  - Transferencia VES

Resultado: el cliente puede pagar una parte en dólares y otra parte en bolívares, siempre que cada pago use un método permitido.

### Financiadora externa

- Método: `Financiadora externa`
- Tipo operativo: `external_financing`
- Estado de pago recomendado: `pending`

Resultado: la orden POS queda abierta y la venta queda en borrador hasta completar la integración específica de financiadoras.

## APIs principales

```txt
GET /api/payment-methods
POST /api/payment-methods
PATCH /api/payment-methods/{paymentMethod}
DELETE /api/payment-methods/{paymentMethod}
```

Las listas de precio aceptan:

```json
{
  "payment_method_ids": [1, 2, 3]
}
```

El checkout POS acepta:

```json
{
  "payments": [
    {
      "payment_method_id": 1,
      "method": "zelle",
      "currency": "USD",
      "amount": 50,
      "reference": "ZL-001",
      "status": "captured"
    }
  ]
}
```

## Validaciones de backend

- El método debe pertenecer al tenant actual.
- El método debe estar activo.
- La moneda del pago debe ser compatible con el método.
- La referencia es obligatoria cuando el método la exige.
- Si la lista de precio tiene restricciones, el método debe estar asociado a esa lista.
- Las reglas se aplican en checkout aunque el frontend o WPF oculten opciones.

## Próximos pasos recomendados

- Crear pantalla WPF para administrar métodos de pago.
- Agregar selector de métodos disponibles en la ventana de pago del POS.
- Permitir pagos mixtos visuales con validación previa antes de confirmar.
- Crear configuración avanzada para financiadoras externas.
- Agregar conciliación y reportes por método de pago.
