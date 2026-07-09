# Plan Del Modulo De Traslados Logisticos

Fecha: 2026-07-09

## Objetivo

Definir el flujo completo para traslados de inventario entre almacenes, sucursales o empresas, con soporte para un modo simple y un modo con validadores logisticos. La meta es que el sistema pueda operar rapido cuando el negocio no requiere control estricto, pero tambien pueda activar checklist, guias, recepcion y diferencias cuando el cliente necesite trazabilidad completa.

## Principios Del Modulo

- El flujo debe ser configurable por empresa.
- Cada empresa mantiene su informacion aislada por tenant.
- Los traslados entre empresas deben quedar auditados de punta a punta.
- Los productos serializados o con IMEI deben validarse por unidad, no solo por cantidad.
- El stock no debe quedar disponible para venta si ya esta reservado o en transito por un traslado.
- Toda diferencia debe tener motivo, usuario, fecha y observacion.
- El administrador debe poder revisar el estado, los responsables y las diferencias desde la web.
- La app de escritorio sera el punto operativo principal para crear, preparar, despachar y recibir traslados.
- La web sera el panel administrativo para monitorear, auditar y reportar.

## Modos De Operacion

### 1. Modo Simple

Pensado para negocios pequenos o flujos internos sin mucha validacion.

Funcionamiento:

- Se crea el traslado desde la app local.
- Se selecciona origen, destino, productos, cantidades y seriales si aplica.
- El sistema valida stock disponible.
- El movimiento se confirma directamente.
- El stock sale del origen y entra al destino.
- Se genera un registro de kardex y auditoria.

Este modo debe ser rapido y con pocos pasos.

### 2. Modo Con Validadores Logisticos

Pensado para traslados entre empresas, sedes o almacenes donde se necesita control de carga y recepcion.

Funcionamiento general:

- Se crea una solicitud de traslado.
- El sistema genera una guia de traslado.
- Un preparador valida lo que realmente se carga.
- Si hay diferencias, debe explicar el motivo.
- El traslado queda despachado y el stock pasa a estado en transito.
- El receptor valida lo que realmente llega.
- Si todo coincide, se completa el traslado.
- Si hay diferencias, queda completado con diferencias o pendiente de decision administrativa.

Este modo es opcional y se activa por empresa.

## Estados Propuestos

- `solicitado`: traslado creado, aun no preparado.
- `en_preparacion`: el preparador esta revisando y cargando productos.
- `preparado`: todo fue validado para despacho.
- `preparado_con_diferencias`: se cargo algo distinto a lo solicitado.
- `despachado`: salio del origen y esta en transito.
- `en_recepcion`: el destino esta validando lo recibido.
- `completado`: recibido sin diferencias.
- `completado_con_diferencias`: recibido con faltantes, sobrantes, danos o seriales distintos.
- `rechazado`: el destino no acepta el traslado.
- `cancelado`: el traslado fue anulado antes de completarse.

## Guia De Traslado

La guia sera el documento operativo del traslado.

Debe incluir:

- Numero unico de guia.
- Empresa origen.
- Sucursal y almacen origen.
- Empresa destino.
- Sucursal y almacen destino.
- Usuario solicitante.
- Fecha de creacion.
- Estado actual.
- Lista de productos.
- Cantidades solicitadas.
- Cantidades preparadas.
- Cantidades recibidas.
- Seriales o IMEI cuando aplique.
- Observaciones.
- Motivos de diferencia.
- Usuario preparador.
- Usuario receptor.
- Fechas de preparacion, despacho y recepcion.

Mas adelante puede imprimirse en PDF o ticket.

## Validadores Y Checklists

### Preparador De Traslado

El preparador es quien revisa lo que se va a cargar.

Debe poder:

- Ver la guia asignada.
- Marcar producto por producto como cargado.
- Escanear seriales o IMEI cuando aplique.
- Registrar cantidad cargada.
- Marcar faltantes.
- Marcar productos danados.
- Explicar motivo si no se carga todo.
- Finalizar preparacion.

Motivos sugeridos:

- `faltante_en_almacen`
- `producto_danado`
- `no_ubicado`
- `serial_no_corresponde`
- `cantidad_diferente`
- `otro`

### Receptor De Traslado

El receptor es quien valida lo que llega al destino.

Debe poder:

- Ver la guia despachada.
- Marcar producto por producto como recibido.
- Escanear seriales o IMEI recibidos.
- Registrar cantidad recibida.
- Reportar faltantes.
- Reportar sobrantes.
- Reportar danos.
- Reportar seriales distintos.
- Agregar observaciones.
- Completar o reportar diferencias.

Motivos sugeridos:

- `faltante_en_recepcion`
- `sobrante_en_recepcion`
- `producto_danado`
- `serial_no_corresponde`
- `paquete_abierto`
- `otro`

## Regla De Stock

### Productos Por Cantidad

En modo con validadores:

- Al solicitar: el stock puede quedar disponible o reservado, segun configuracion.
- Al preparar: el stock debe quedar reservado para traslado.
- Al despachar: el stock sale del disponible y pasa a en transito.
- Al recibir: el stock entra al almacen destino.
- Si hay diferencia: solo entra lo recibido; lo faltante queda como incidencia.

### Productos Serializados O Con IMEI

Los estados de cada unidad deben seguir una ruta controlada:

- `disponible`
- `reservado_traslado`
- `en_transito`
- `disponible_destino`
- `incidencia_traslado`

Un IMEI o serial en traslado no debe venderse en POS.

## Permisos Propuestos

Para mantener el modulo ordenado:

- `transfers.view`: ver traslados.
- `transfers.create`: crear solicitudes.
- `transfers.prepare`: preparar o cargar traslados.
- `transfers.dispatch`: despachar.
- `transfers.receive`: recibir.
- `transfers.cancel`: cancelar.
- `transfers.resolve_differences`: resolver diferencias.
- `transfers.admin`: administrar configuracion y reportes.

Roles sugeridos:

- Administrador.
- Encargado de inventario.
- Preparador de traslado.
- Receptor de traslado.
- Auditor logistico.

## Fases De Implementacion

### Fase 1 - Base Backend Y Configuracion

Objetivo: dejar lista la estructura base del modulo.

Incluye:

- Configuracion por empresa para activar modo simple o modo con validadores.
- Tablas para traslados, items, guias, checklist, diferencias y auditoria.
- Estados del traslado.
- Permisos base.
- Eventos de sincronizacion local-nube y nube-local.
- Tests en PostgreSQL.

Criterio de cierre:

- Se pueden crear traslados desde API.
- Se genera una guia.
- El sistema respeta aislamiento por empresa.
- Los tests validan que una empresa no vea traslados de otra.

### Fase 2 - Escritorio: Crear Traslado Y Guia

Objetivo: permitir crear un traslado desde la app local.

Incluye:

- Pantalla de traslados en escritorio.
- Seleccion de origen y destino.
- Buscador de productos.
- Seleccion de cantidades o seriales/IMEI.
- Generacion de guia.
- Validacion de stock.
- Mensajes de error claros en espanol.

Criterio de cierre:

- El usuario crea un traslado.
- El traslado queda en estado `solicitado`.
- La guia queda disponible.
- El stock no se mezcla entre empresas.

### Fase 3 - Escritorio: Preparacion Y Carga

Objetivo: permitir al preparador validar lo que realmente se carga.

Incluye:

- Lista de guias pendientes de preparacion.
- Checklist por producto.
- Escaneo de seriales/IMEI.
- Registro de cantidades cargadas.
- Motivos obligatorios si hay diferencias.
- Cambio de estado a `preparado` o `preparado_con_diferencias`.

Criterio de cierre:

- El preparador puede cerrar la carga.
- El sistema bloquea stock para venta.
- Las diferencias quedan auditadas.

### Fase 4 - Escritorio: Despacho Y Recepcion

Objetivo: controlar salida y entrada del traslado.

Incluye:

- Despacho desde origen.
- Estado `despachado`.
- Stock en transito.
- Recepcion en destino.
- Checklist de recepcion.
- Escaneo de seriales/IMEI recibidos.
- Cierre como `completado` o `completado_con_diferencias`.

Criterio de cierre:

- Lo recibido entra al destino.
- Lo no recibido queda como incidencia.
- No se puede recibir mas de lo permitido sin dejar diferencia.

### Fase 5 - Web Administrativa

Objetivo: monitorear y auditar traslados desde el portal web.

Incluye:

- Vista de traslados por empresa, sucursal, estado y fecha.
- Filtros compactos de alta densidad.
- Detalle de guia.
- Diferencias por producto.
- Responsables de carga y recepcion.
- Reporte de faltantes, sobrantes y danos.
- Acciones administrativas para resolver incidencias.

Criterio de cierre:

- El administrador ve el estado completo.
- Puede detectar diferencias rapido.
- Puede exportar o revisar historial.

### Fase 6 - Sincronizacion

Objetivo: asegurar que los traslados funcionen local-nube y nube-local.

Incluye:

- Eventos outbox para crear, preparar, despachar y recibir.
- Eventos de diferencia.
- Sincronizacion de guias.
- Sincronizacion de seriales/IMEI.
- Reintentos si no hay internet.
- Prevencion de duplicados por UUID.

Criterio de cierre:

- Un traslado creado localmente sube a la nube.
- Un traslado creado o actualizado desde la nube baja al local correspondiente.
- Los eventos se procesan una sola vez.

### Fase 7 - Mejoras Futuras

Estas ideas quedan pendientes para evolucionar el modulo:

- PDF imprimible de guia.
- Firma digital del preparador.
- Firma digital del receptor.
- Evidencia con fotos.
- Datos de transporte: chofer, placa, empresa de encomienda.
- Codigo QR en la guia.
- Notificaciones al administrador cuando haya diferencias.
- Reglas por empresa para permitir o bloquear diferencias.
- Aprobacion administrativa antes de completar con diferencias.
- Dashboard logistico: tiempos promedio, incidencias, productos mas problematicos.
- Control de rutas y entregas.
- Integracion con pedidos para convertir pedido aprobado en traslado.

## Recomendacion De Inicio

Comenzar por la Fase 1 en backend y luego integrar la Fase 2 en escritorio.

La razon es que el escritorio necesita consumir una base solida de estados, permisos, guias y stock. Si el backend queda bien definido desde el inicio, el modulo web podra montarse despues sin rehacer la logica.

## Nota Operativa

Este modulo debe mantenerse separado del POS, caja, compras y centro de inventario, aunque use sus datos. La responsabilidad del modulo de traslados es mover inventario con trazabilidad, no vender, comprar ni ajustar stock libremente.
