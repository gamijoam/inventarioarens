# Centro Tecnico Local

El Centro Tecnico Local (`/support`) es la consola grafica para una instalacion local. Solo se puede abrir desde `localhost` o `127.0.0.1`; no se expone en la nube.

## Vincular una empresa

1. Desde la empresa en la nube, genera un codigo temporal de vinculacion.
2. Abre `/support` en la computadora local.
3. Introduce el codigo, el nombre/codigo de la computadora y el usuario local autorizado.
4. Pulsa **Vincular y descargar empresa**.

La consola guarda el token por empresa en la configuracion local protegida. No lo muestra en pantalla y cada empresa conserva su propio nodo, intervalo y worker.

## Diagnostico por empresa

Cada tarjeta muestra:

- **Outbox**: eventos locales pendientes de subir a la nube.
- **Outbox fallidos**: eventos locales que requieren diagnostico.
- **Inbox recibidos**: eventos descargados que aun no se han aplicado.
- **Inbox fallidos**: eventos descargados que no pudieron aplicarse.
- **Aplicados**: eventos ya incorporados a la base local.
- ultimo intento, ultima sincronizacion exitosa y ultimo error.

Un worker puede tener un proceso vivo y aun asi estar detenido funcionalmente. Por eso el diagnostico considera tambien el ultimo ciclo real y los contadores del inbox/outbox.

## Acciones

- **Sincronizar ahora**: ejecuta una ronda manual de subida, descarga, aplicacion y descarga de imagenes.
- **Reintentar fallidos**: devuelve los eventos fallidos al estado recibible, los aplica nuevamente y actualiza las imagenes pendientes. No borra eventos.
- **Iniciar/Reiniciar/Detener worker**: controla el worker de Windows y su tarea programada.
- **Reparar inicio automatico**: vuelve a registrar la tarea de Windows para que sobreviva al reinicio del equipo.

Las acciones son idempotentes: repetirlas no debe duplicar eventos porque la sincronizacion usa `event_uuid` e idempotencia.

## Impresion

La tarjeta de instalacion muestra si el agente local responde en `127.0.0.1:17777`. Si aparece detenido, iniciar el agente con el instalador/herramienta local correspondiente y actualizar la consola. El agente no controla el estado de caja ni el worker; son servicios independientes.

## Diagnostico recomendado

1. Actualizar la pantalla.
2. Revisar **Inbox fallidos** y **Outbox fallidos**.
3. Ejecutar **Reintentar fallidos**.
4. Ejecutar **Sincronizar ahora**.
5. Si el ultimo ciclo sigue atrasado, reiniciar o reparar el worker.
6. Revisar el mensaje de error concreto antes de volver a intentar.

No eliminar la base SQLite para corregir un atasco de sincronizacion. Primero conservar el error y usar reintentos; la recuperacion completa desde la nube es una operacion separada.
