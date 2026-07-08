# Recuperación de eventos de clientes ignorados

## Contexto

Se detectó un caso donde un cliente creado desde el portal web de la nube llegaba a la base local como evento `customer.created`, pero quedaba con estado `ignored` en `sync_inbox`. En ese estado el cliente no se insertaba en la tabla `customers`, aunque el payload recibido desde la nube estuviera completo.

## Causa

El evento ya había sido recibido por el local, pero fue procesado como ignorado. Esto puede ocurrir cuando una instalación local procesa eventos con una versión anterior del aplicador o cuando el tipo de evento queda registrado antes de que el módulo local conozca cómo aplicarlo.

## Implementación

- El aplicador de sincronización ahora puede reintentar eventos conocidos que hayan quedado en estado `ignored`.
- La recuperación aplica a eventos de catálogo y configuración como clientes, productos, listas de precio, precios por lista, almacenes, sucursales, tasas, métodos de pago y cajas.
- Los eventos desconocidos siguen quedando ignorados para no procesar tipos no soportados.
- El comando `sync:apply-inbox` ahora recupera esos eventos conocidos y los pasa a `applied` si el payload es valido.

## Resultado probado

Se reintentó localmente el evento `customer.created` del cliente `SOLEDAD`.

Resultado:

- Cliente creado en `customers`.
- Evento `customer.created` actualizado de `ignored` a `applied`.
- Sin errores en `last_error`.

## Pruebas automatizadas

Se agregó una prueba para cubrir el caso:

- Un evento `customer.created` queda previamente como `ignored`.
- Se ejecuta `sync:apply-inbox`.
- El cliente se crea localmente.
- El evento queda como `applied`.

Comando usado:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Sync\SyncApiTest.php tests\Feature\Customers\CustomerApiTest.php
```

Resultado:

```text
27 tests, 186 assertions
```
