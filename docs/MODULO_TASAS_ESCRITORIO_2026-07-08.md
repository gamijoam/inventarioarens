# Modulo de tasas en escritorio

## Objetivo

Agregar una pantalla local para consultar las tasas vigentes que usa la operacion diaria sin convertir el escritorio en el centro administrativo.

La regla queda asi:

- El portal web administra tipos de tasa y valores vigentes.
- La app de escritorio consulta tasas para operacion, POS, productos y caja.
- La sincronizacion lleva los cambios de la nube hacia cada local.

## Pantalla agregada

En el centro de modulos de escritorio se agrego la tarjeta **Tasas**.

La pantalla muestra:

- Tipos de tasa configurados para la empresa.
- Tasa vigente por tipo.
- Tasa predeterminada.
- Tipos activos.
- Tipos activos sin tasa vigente.
- Fecha de ultima consulta.

Tambien incluye:

- **Actualizar**: vuelve a consultar las tasas desde el backend local.
- **Sincronizar ahora**: ejecuta un ciclo manual de sincronizacion para traer cambios desde la nube y luego recarga la pantalla.

## APIs consumidas

La app de escritorio consume estas APIs locales:

- `GET /api/currency/rate-types`
- `GET /api/currency/rates/current`

No escribe directamente en PostgreSQL.

## Permisos

La tarjeta **Tasas** se habilita con:

- `currency.view`

La edicion de tasas queda reservada para el portal web con:

- `currency.manage`

## Comportamiento esperado

1. El administrador cambia una tasa desde la web.
2. La nube genera eventos de sincronizacion.
3. El worker local descarga y aplica el cambio.
4. La pantalla local de tasas refleja la tasa vigente.
5. El POS usa la tasa correcta al vender.

## Archivos principales

- `desktop/InventoryDesktop/Modules/Currency/CurrencyRatesView.xaml`
- `desktop/InventoryDesktop/Modules/Currency/CurrencyRatesView.xaml.cs`
- `desktop/InventoryDesktop/Modules/Currency/CurrencyRatesViewModel.cs`
- `desktop/InventoryDesktop/Modules/Currency/CurrencyDtos.cs`
- `desktop/InventoryDesktop/ShellView.xaml`
- `desktop/InventoryDesktop/ShellView.xaml.cs`

## Nota operativa

Si una tasa no aparece en el escritorio, primero se debe revisar:

1. Que exista en la nube.
2. Que el worker este activo.
3. Que la empresa local este sincronizada.
4. Que el usuario tenga permiso `currency.view`.
5. Que el backend local este apuntando a la base correcta.
