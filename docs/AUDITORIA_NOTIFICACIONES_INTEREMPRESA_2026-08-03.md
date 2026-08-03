# Auditoria de notificaciones interempresa

## Problema encontrado

Las notificaciones dependian de eventos WebSocket transitorios y de un contador
de solicitudes pendientes. El estado leido se guardaba en `localStorage`, por lo
que variaba entre navegadores y computadoras. Ademas, solo se emitian algunos
eventos y el canal privado `tenant.{tenantId}` no estaba registrado en
`routes/channels.php`.

Esto producia tres fallos visibles:

- una desconexion breve hacia perder el aviso;
- preparar, despachar, entregar o recibir no siempre generaba una notificacion;
- el contador podia indicar actividad sin ofrecer una bandeja auditable.

## Solucion aplicada

Las notificaciones ahora son registros persistentes y tenant-scoped:

- `intercompany_notifications` guarda el evento, mensaje, solicitud, empresa
  destinataria, actor y fecha;
- `intercompany_notification_reads` guarda el estado leido por usuario;
- cada cambio de estado crea la notificacion dentro del flujo del backend;
- WebSocket solo acelera la actualizacion visual;
- el frontend consulta la bandeja cada 15 segundos como respaldo confiable;
- una falla de Reverb no revierte ni bloquea la operacion interempresa.

Se notifican las etapas `created`, `accepted`, `rejected`, `cancelled`,
`prepared`, `dispatched`, `delivered` y `received`. El destinatario se determina
segun la responsabilidad de cada etapa, no segun quien tenga abierta la pantalla.

## Experiencia de usuario

El encabezado muestra una campana con el total pendiente. La bandeja permite:

- consultar actividad reciente;
- abrir directamente la solicitud relacionada;
- marcar un aviso como leido;
- marcar toda la bandeja como leida.

El menu lateral usa el mismo total persistente. Ya no depende de comparar
cantidades ni del almacenamiento del navegador.

## Operacion

La migracion requerida es:

`2026_08_03_120000_create_intercompany_notifications_tables.php`

En cada entorno se aplica con `php artisan migrate --force`. El tiempo real
requiere que Reverb este disponible, pero la entrega funcional de avisos no
depende de el gracias al polling de respaldo.

