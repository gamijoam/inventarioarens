# Sincronizacion local y nube

## Objetivo

Preparar el sistema para trabajar rapido en local y sincronizar con una base central en la nube sin perder aislamiento por empresa. El local seguira operando aunque internet falle, y la nube podra enviar cambios administrativos como precios, productos, permisos, tasas y configuraciones.

## Decision tecnica

La base sera un modelo **local-first** con **Transactional Outbox** en ambos lados:

- Local -> nube: ventas, pagos, movimientos de caja, inventario, clientes creados en tienda y auditorias operativas.
- Nube -> local: precios, listas de precio, tasas, permisos, usuarios, productos administrados, cajas y configuraciones.
- WebSocket queda como acelerador opcional para avisar "hay cambios"; la fuente confiable sera siempre el outbox con acuse de recibo.
- Polling sera el mecanismo base porque es simple, tolera cortes y evita depender de conexiones abiertas permanentes.

## Fases

### Fase 1 - Diseno y contratos

- Definir tipos de evento por modulo.
- Definir reglas de conflicto por dominio.
- Definir identificadores globales para nodos, eventos y registros sincronizables.
- Documentar APIs de sincronizacion.

### Fase 2 - Estructura base

- Crear `sync_nodes` para registrar cada instalacion local, servidor nube o sucursal.
- Crear `sync_outbox` para eventos pendientes de enviar.
- Crear `sync_inbox` para eventos recibidos y evitar duplicados.
- Crear `sync_states` para recordar el ultimo avance por direccion.

### Fase 3 - Local hacia nube

- Emitir eventos al confirmar ventas POS.
- Emitir eventos al abrir/cerrar caja.
- Emitir eventos al registrar entradas, salidas, traslados y ajustes.
- Subir eventos por lotes con reintentos, bloqueo y trazabilidad.

### Fase 4 - Nube hacia local

- Emitir eventos cuando el panel web cambie precios, listas, tasas, usuarios, permisos o productos.
- Permitir que cada local consulte eventos pendientes.
- Registrar acuses de recibo para no reenviar eventos ya aplicados.

### Fase 5 - Worker local

- Crear proceso en segundo plano para subir outbox local.
- Crear proceso en segundo plano para descargar outbox de nube.
- Aplicar eventos en transacciones locales.
- Manejar reintentos con backoff y errores visibles.

### Fase 6 - Conflictos

- Ventas, pagos, caja y kardex: son eventos append-only. No se pisan; se agregan y se auditan.
- Precios, tasas, permisos y configuracion global: gana la nube por defecto.
- Productos: se separan campos administrados por nube y campos operativos locales.
- Clientes: se permiten altas locales y luego se consolidan por documento, telefono o UUID.

### Fase 7 - Observabilidad

- Mostrar estado de sincronizacion por empresa y local.
- Mostrar cola pendiente, fallida y ultimo evento aplicado.
- Agregar alertas cuando un local tenga mucho tiempo sin sincronizar.

### Fase 8 - WebSocket opcional

- Usar WebSocket solo para despertar al local cuando existan cambios nuevos.
- Si el WebSocket cae, el polling mantiene el sistema funcionando.
- El evento real se descarga igual por API y queda auditado.

## Tablas base

### sync_nodes

Representa cada origen o destino de sincronizacion:

- Local comercial.
- Servidor nube.
- Sucursal.
- Estacion de trabajo futura.

### sync_outbox

Guarda eventos producidos localmente o en la nube antes de enviarlos. Debe ser transaccional: el cambio de negocio y el evento nacen juntos o no nace ninguno.

### sync_inbox

Guarda eventos recibidos para impedir doble aplicacion si el mismo evento llega dos veces.

### sync_states

Guarda el ultimo punto de sincronizacion por nodo y direccion.

## APIs previstas

- `POST /api/sync/events/push`: el local envia eventos a la nube.
- `GET /api/sync/events/pull`: el local pide eventos pendientes desde la nube.
- `POST /api/sync/events/{event_uuid}/ack`: el receptor confirma que aplico un evento.
- `GET /api/sync/status`: estado de colas, errores y ultimo intercambio.

## Seguridad

- Cada nodo tendra token propio.
- Cada peticion viajara con empresa y nodo.
- Los eventos tendran `event_uuid` e `idempotency_key`.
- Los tokens de nodo se podran rotar.
- Un nodo solo podra leer eventos destinados a su empresa y alcance.

## Primer alcance implementado

En esta primera fase se deja solo la base de datos de sincronizacion y una prueba especifica. Todavia no se emiten eventos desde POS, caja ni inventario. Eso se hara por modulo para no contaminar la logica ya estable.

