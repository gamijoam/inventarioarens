# Worker automático: reinicio e instalación de tarea

## Contexto

Se detectó un caso donde la sincronización manual funcionaba, pero la sincronización automática no aplicaba un cliente creado desde la nube.

El evento sí bajaba al local, pero quedaba en `sync_inbox` con estado `ignored`. Esto ocurría porque el worker continuo de Windows seguía ejecutándose con una versión anterior del código, cargada en memoria antes del arreglo de recuperación de eventos ignorados.

## Acciones realizadas

- Se detuvo el worker antiguo de la empresa `demo-valencia`.
- Se aplicó manualmente la bandeja local con `sync:apply-inbox` para recuperar el evento pendiente.
- Se confirmó que el cliente nuevo quedó creado localmente.
- Se inició nuevamente el worker para que cargara el código actualizado.
- Se instaló la tarea programada de Windows `SistemaInventarioSync-demo-valencia`.

## Resultado

La empresa `demo-valencia` quedó con:

- Worker activo.
- Ciclos automáticos cada 15 segundos.
- Tarea de Windows instalada para revisar cada 5 minutos si el worker está detenido y levantarlo de nuevo.
- Eventos nuevos de clientes aplicados correctamente.

## Verificación

Se confirmó que el evento `customer.created` que estaba en `ignored` pasó a `applied` y el cliente apareció en la tabla local `customers`.

También se validó que el worker nuevo quedó ejecutando ciclos limpios:

```text
Subidos: 0 | Bajados: 0 | Aplicados: 0 | Ignorados: 0 | Fallos: 0
```

## Pruebas

Se ejecutaron pruebas específicas de sincronización y clientes:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests\Feature\Sync\SyncWorkerCommandTest.php tests\Feature\Customers\CustomerApiTest.php
```

Resultado:

```text
16 tests, 99 assertions
```

## Nota operativa

Cuando se actualiza el código del worker, cualquier proceso `sync:daemon` ya abierto debe reiniciarse. Los workers continuos cargan la aplicación Laravel en memoria, por lo que no toman automáticamente cambios nuevos del código hasta detenerlos e iniciarlos otra vez.

En instalaciones nuevas, el configurador debe dejar instalada la tarea de Windows para que la sincronización no dependa de abrir manualmente la consola o el módulo principal.
