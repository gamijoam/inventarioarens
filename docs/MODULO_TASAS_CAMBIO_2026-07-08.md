# Modulo de tasas de cambio

## Objetivo

El modulo de tasas permite administrar los tipos de cambio que usa el sistema para vender productos en Venezuela con precios en USD y equivalentes en bolivares.

La base operativa es:

- Moneda base comercial: USD.
- Moneda local: VES.
- Tipos de tasa configurables por empresa: por ejemplo BCV, Paralelo, Promocional u otra.
- Cada producto puede usar una tasa especifica segun su configuracion.
- El POS debe respetar la tasa asociada al producto o lista de precio.

## APIs disponibles

### Tipos de tasa

- `GET /api/currency/rate-types`
- `POST /api/currency/rate-types`
- `GET /api/currency/rate-types/{id}`
- `PUT /api/currency/rate-types/{id}`
- `DELETE /api/currency/rate-types/{id}`

Campos principales:

- `code`: codigo unico por empresa. Ejemplo: `BCV`, `PARALELO`.
- `name`: nombre visible.
- `is_default`: indica si sera la tasa predeterminada.
- `is_active`: permite desactivar una tasa sin borrar historial.

### Valores de tasa

- `GET /api/currency/rates`
- `GET /api/currency/rates/current`
- `POST /api/currency/rates`
- `GET /api/currency/rates/{id}`
- `PATCH /api/currency/rates/{id}/activate`
- `PATCH /api/currency/rates/{id}/deactivate`

Campos principales:

- `exchange_rate_type_id`: tipo de tasa.
- `base_currency`: `USD`.
- `quote_currency`: `VES`.
- `rate`: valor en Bs por 1 USD.
- `effective_at`: fecha y hora de vigencia.
- `is_active`: si queda vigente.
- `source`: fuente del valor, por ejemplo Manual o BCV.

## Sincronizacion

Los cambios de tasas generan eventos de sincronizacion:

- `exchange_rate_type.created`
- `exchange_rate_type.updated`
- `exchange_rate.created`
- `exchange_rate.updated`

Estos eventos viajan por el outbox hacia los nodos locales. Asi, si un administrador cambia la tasa desde la web, los locales deben recibir el cambio automaticamente mediante el worker.

La foto inicial de sincronizacion tambien incluye:

- Tipos de tasa.
- Valores de tasa.

Esto permite preparar una computadora nueva sin sembrar datos manualmente.

## Pruebas

Pruebas especificas del modulo:

- `tests/Feature/Currency/CurrencyApiTest.php`

Cobertura agregada:

- Creacion de tipos de tasa.
- Activacion de tasas BCV y paralelo.
- Aislamiento por empresa.
- Permisos.
- Registro de eventos en `sync_outbox` para sincronizacion.

## Pendiente natural

- Conectar el uso de tasas con reportes financieros avanzados.
- Revisar reglas de conflicto si una tasa cambia localmente y tambien desde la nube.
- Agregar filtros por tipo de tasa y fecha si el historial crece demasiado.
- Cuando se construya el nuevo frontend web, agregar pantalla de administracion de tasas consumiendo estos endpoints.
