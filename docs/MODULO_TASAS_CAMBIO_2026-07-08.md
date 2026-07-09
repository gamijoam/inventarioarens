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

## Portal web administrativo

Se agrego el modulo `Tasas` en el portal administrativo web.

Desde esta pantalla se puede:

- Consultar tipos de tasa.
- Crear tipos de tasa.
- Editar nombre, codigo, estado y valor predeterminado.
- Desactivar tipos de tasa.
- Registrar una nueva tasa vigente.
- Revisar historial reciente.

La pantalla sigue la regla de interfaz administrativa de alta densidad: tablas compactas, controles reducidos y sin bloques decorativos grandes.

### Correccion de visibilidad en nube

Se corrigio el registro de secciones del portal administrativo para que `Tasas` quede disponible dentro del menu web.

El problema detectado era visual y de inicializacion:

- El boton `Tasas` existia en la vista Blade.
- El panel HTML del modulo tambien existia.
- En `resources/js/admin.js` faltaba registrar la seccion `rates` dentro de `portalSections`.
- Ademas, el estado `state.rates` estaba declarado dos veces, por lo que la segunda declaracion pisaba la configuracion operativa del modulo.

Con la correccion:

- `Tasas` abre su panel propio en el portal administrativo.
- El estado del modulo conserva `loaded`, `selectedType`, `rateTypes` y `rates`.
- `Reportes` tambien queda registrado como seccion valida del portal para evitar que vuelva al resumen al hacer clic.

Para que se vea en produccion, despues de hacer `pull` en el VPS se debe compilar el frontend con `npm run build` y limpiar cache si aplica.

## Escritorio local

Se agrego una pantalla de consulta en la app WPF para revisar tasas vigentes desde el local.

La pantalla local no crea ni modifica tasas. Su objetivo es operativo:

- confirmar la tasa vigente antes de vender;
- detectar tipos de tasa activos sin valor vigente;
- sincronizar manualmente la empresa si se sospecha que falta una actualizacion;
- mantener al POS y al equipo local alineados con la configuracion administrativa de la nube.

Documentacion especifica:

- `docs/MODULO_TASAS_ESCRITORIO_2026-07-08.md`

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

- Validar visualmente el modulo en el VPS.
- Conectar el uso de tasas con reportes financieros avanzados.
- Revisar reglas de conflicto si una tasa cambia localmente y tambien desde la web.
- Agregar filtros por tipo de tasa y fecha si el historial crece demasiado.
