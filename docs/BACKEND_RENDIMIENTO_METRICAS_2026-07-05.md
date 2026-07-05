# Backend - Métricas de rendimiento por módulo

## Objetivo

Medir pasos internos del backend para detectar con precisión qué parte de una API está lenta. Esto aplica primero a POS y Centro de Inventario, porque son los módulos más usados en operación diaria.

## Implementado

- Se agregó `App\Support\Performance\PerformanceProbe`.
- Cada medición escribe en el log de Laravel con el prefijo `PERF OK BACKEND` o `PERF LENTO BACKEND`.
- El log incluye:
  - nombre de la operación;
  - duración en milisegundos;
  - umbral usado para marcar lento;
  - contexto operativo cuando aplica, como producto, orden, cantidad de items, búsqueda o acción.

## POS medido

- `POS checkout total`
- `POS validar metodos de pago`
- `POS crear venta borrador`
- `POS resolver pago`
- `POS registrar pago en caja`
- `POS confirmar venta`
- `POS sincronizar cuentas por cobrar`
- `POS reservar inventario pendiente`
- `POS cargar respuesta checkout`
- `POS completar orden pendiente total`
- `POS pendiente validar metodos de pago`
- `POS pendiente resolver pago`
- `POS pendiente registrar pago en caja`
- `POS pendiente liberar reserva`
- `POS pendiente confirmar venta`
- `POS pendiente sincronizar cuentas por cobrar`
- `POS pendiente cargar respuesta`

## Centro de Inventario medido

- `InventoryCenter resumen total`
- `InventoryCenter resumen productos`
- `InventoryCenter resumen metricas`
- `InventoryCenter resumen alertas`
- `InventoryCenter exportar CSV`
- `InventoryCenter exportar filas`
- `InventoryCenter detalle producto total`
- `InventoryCenter detalle stock total`
- `InventoryCenter detalle stock almacenes`
- `InventoryCenter detalle seriales recientes`
- `InventoryCenter detalle movimientos recientes`
- `InventoryCenter detalle auditorias recientes`
- `InventoryCenter seriales pagina`
- `InventoryCenter movimientos pagina`
- `InventoryCenter stock por almacen pagina`
- `InventoryCenter auditorias pagina`
- `InventoryCenter accion masiva total`
- `InventoryCenter accion masiva cargar productos`
- `InventoryCenter accion masiva precio lista producto`

## Cómo usarlo

- Revisar `storage/logs/laravel.log`.
- Buscar `PERF LENTO BACKEND` para ver los cuellos de botella.
- Comparar el total de una API con sus pasos internos.
- Si el total es lento pero los pasos internos son rápidos, revisar serialización de respuesta, middleware, red o ambiente Docker.
- Si un paso interno es lento, optimizar ese servicio específico con índices, menos relaciones, paginación o consultas agregadas.

## Pruebas

- Se ejecutó `docker compose run --rm app_test php -l` sobre los archivos PHP modificados.
- Resultado: sin errores de sintaxis.
- Se ejecutó `docker compose run --rm app_test ./vendor/bin/pint` sobre los archivos PHP modificados.
- Resultado: formato aplicado correctamente.
- Se ejecutó `docker compose run --rm app_test php artisan test tests/Feature/POS/PosCheckoutApiTest.php tests/Feature/InventoryCenter/InventoryCenterSummaryApiTest.php`.
- Resultado: 36 pruebas pasadas, 265 aserciones.
