# Guía De Mejoras Del POS Para Venezuela

Fecha inicial: 2026-08-16
Última actualización: 2026-08-17
Proyecto: INVENTARIOARENS

## 1. Propósito

Esta guía explica, en lenguaje de negocio, qué hace actualmente el programa, qué mejoras se deben realizar y cómo se trabajarán sin poner en riesgo las ventas, el inventario ni la información existente.

La guía complementa la auditoría técnica `AUDITORIA_FISCAL_FUNCIONAL_POS_VENEZUELA_2026-08-16.md`.

## 2. Qué Es Actualmente El Programa

INVENTARIOARENS es actualmente una plataforma comercial de inventario y punto de venta.

Permite:

- Administrar empresas, sucursales, usuarios y permisos.
- Registrar productos, categorías, marcas, códigos de barras, variantes, IMEI y seriales.
- Controlar almacenes, existencias, traslados y movimientos de inventario.
- Realizar ventas y órdenes pendientes.
- Manejar cajas, pagos y diferentes métodos de cobro.
- Trabajar con USD y VES usando tasas de cambio guardadas en cada operación.
- Administrar clientes, cuentas por cobrar, devoluciones y créditos a favor.
- Configurar promociones y listas de precios.
- Imprimir tickets, recibos y documentos comerciales.
- Sincronizar información entre instalaciones locales y la nube.

## 3. Qué No Es Todavía

El ticket actual es un **documento comercial no fiscal**. Esto está indicado en el propio ticket.

El programa todavía no debe presentarse como:

- Sistema de facturación fiscal venezolana.
- Emisor de facturas fiscales.
- Proveedor de facturación digital autorizado.
- Integración con máquina fiscal.
- Emisor de notas de crédito o débito fiscales.
- Sistema homologado o certificado por una autoridad tributaria.

Una factura fiscal no se obtiene solamente cambiando el título de un ticket o generando un PDF. Requiere definir el medio autorizado de emisión y cumplir los datos, numeración, control, impuestos, trazabilidad y conservación que correspondan.

## 4. Beneficios Que Ya Tiene El Programa

### Para el dueño

- Control centralizado de varias empresas y sucursales.
- Menos trabajo manual para saber cuánto se vendió y cuánto queda.
- Control de cajas, pagos y saldos de clientes.
- Inventario por almacén y seguimiento de productos serializados.
- Operación local con sincronización hacia la nube.

### Para el vendedor

- Búsqueda rápida de productos.
- Listas de precios y promociones.
- Órdenes pendientes para separar la preparación de la venta y el cobro.
- Soporte para diferentes formas de pago.

### Para el almacén

- Movimientos de entrada, salida y traslado.
- Control de IMEI y seriales.
- Alertas de productos bajos o agotados.
- Historial de movimientos.

### Para el cliente

- Detalle claro de la compra.
- Control de devoluciones y créditos.
- Precios y pagos registrados en la moneda correspondiente.

## 5. Roadmap General

### Etapa 1: Proteger Las Operaciones Actuales

Objetivo: evitar cobros duplicados, movimientos dobles, saldos incorrectos y pérdida de eventos de sincronización.

### Etapa 2: Preparar La Información Fiscal

Objetivo: registrar correctamente razón social, RIF, domicilio, sucursales, condición frente al IVA y demás datos del emisor.

### Etapa 3: Incorporar IVA Y Datos Tributarios

Objetivo: distinguir productos gravados, exentos y exonerados, calcular bases e impuestos y guardar una fotografía de los valores utilizados en cada operación.

### Etapa 4: Elegir El Medio De Facturación

Objetivo: decidir con un contador si se utilizará máquina fiscal, forma libre, imprenta digital autorizada o un proveedor externo.

### Etapa 5: Implementar Documentos Fiscales

Objetivo: crear facturas, notas de crédito, notas de débito, numeración, series y números de control según el medio elegido.

### Etapa 6: Mejorar Reportes Y Operación

Objetivo: mejorar informes de ventas, IVA, cajas, devoluciones, auditoría y controles administrativos.

## 6. Etapa 1 En Lenguaje Sencillo

La primera etapa no cambia la forma fiscal del programa. Se concentra en que el programa sea más seguro y confiable en el trabajo diario.

### 6.1 Evitar cobros duplicados

Problema que se evita:

- El cajero presiona dos veces el botón.
- El internet se corta después de que el servidor recibió la venta.
- El programa reintenta una petición y crea otra venta.

Resultado esperado:

- La misma intención de cobro produce una sola venta.
- El segundo intento devuelve el resultado original.
- El dinero, inventario, caja, comisiones y sincronización se registran una sola vez.

### 6.2 Evitar movimientos duplicados de inventario

Problema que se evita:

- Dos personas aprueban el mismo ajuste.
- Un doble clic descuenta dos veces.
- El programa cambia el stock pero no termina de registrar el movimiento.

Resultado esperado:

- Una aprobación solo puede aplicarse una vez.
- Si algo falla, el inventario vuelve al estado anterior.
- El historial muestra quién aprobó y qué movimiento produjo.

### 6.3 Proteger los créditos a favor

Problema que se evita:

- Dos ventas utilizan el mismo saldo al mismo tiempo.
- Una devolución genera dos créditos por un reintento.
- El saldo del cliente queda negativo.

Resultado esperado:

- El crédito se descuenta una sola vez.
- El saldo no puede utilizarse por encima de lo disponible.
- Cada crédito y cada aplicación tienen una operación identificable.

### 6.4 Hacer confiable la sincronización

Problema que se evita:

- La nube responde con error y el equipo local cree que todo salió bien.
- Un evento se marca como terminado antes de ser aplicado.
- Dos workers procesan el mismo evento.

Resultado esperado:

- Los eventos fallidos permanecen pendientes.
- Los eventos se reintentan sin duplicar sus efectos.
- La consola puede mostrar qué está pendiente, fallido o aplicado.

### 6.5 Liberar reservas abandonadas

Problema que se evita:

- Una orden pendiente reserva productos y nunca se cierra.
- El stock queda bloqueado aunque el cliente no haya comprado.

Resultado esperado:

- Una reserva vigente se conserva.
- Una reserva vencida se libera automáticamente.
- Liberar la misma reserva dos veces no aumenta el stock incorrectamente.

## 7. Etapa 1.1 Ya Realizada: Claves De Idempotencia POS

La primera subetapa se implementó siguiendo TDD.

### Cambios realizados

- Las claves de idempotencia ahora se separan por empresa.
- Una empresa no puede recibir la respuesta cacheada de otra empresa.
- Una clave de más de 191 caracteres se rechaza antes de ejecutar la operación.
- Una reserva duplicada no ejecuta nuevamente la operación.
- Si una operación lanza una excepción, se libera la clave para permitir un reintento.
- Las respuestas `5xx` no quedan bloqueadas durante 24 horas.
- Checkout, órdenes pendientes, pagos de órdenes y cancelaciones POS envían `Idempotency-Key` desde el cliente frontend.
- La misma intención de frontend conserva la misma clave durante un reintento.

### Pruebas realizadas

- Middleware de idempotencia: aislamiento, carrera, excepción y longitud máxima.
- Checkout repetido con la misma clave.
- Checkout con body diferente.
- Pago de una orden pendiente repetido.
- Efectos únicos en pagos, caja e inventario.
- Suite POS e infraestructura backend: `93/93` tests verdes, `582` aserciones.
- Suite API frontend POS: `33/33` tests verdes.
- TypeScript: limpio.
- ESLint de archivos afectados: limpio.
- Pint de archivos PHP afectados: limpio.

### Limitación pendiente

La herramienta `infection/infection` no está instalada actualmente, por lo que las pruebas de mutación todavía no se han ejecutado. Antes de adoptar esa herramienta se debe evaluar si se agregará como dependencia de desarrollo del proyecto.

## 8. Próximas Subetapas De La Etapa 1

### Etapa 1.2: Aprobaciones De Inventario

Estado: completada.

- Las rutas de crear, aprobar y rechazar movimientos manuales usan idempotencia.
- La aprobación bloquea el movimiento y valida nuevamente que siga pendiente.
- El cambio de stock y el cambio de estado ocurren dentro de una misma transacción.
- Un reintento con la misma clave devuelve el resultado original y no crea otro movimiento.
- El kardex guarda la referencia al movimiento manual que originó el cambio.
- Si no hay stock suficiente, el movimiento permanece pendiente y no se crea un movimiento parcial.
- Verificación: Inventory backend `67/67`; frontend manual movements `5/5`; TypeScript y ESLint limpios.

### Etapa 1.3: Créditos A Favor

Estado: completada.

- El saldo se recalcula después de bloquear el cliente.
- Una misma operación de emisión o aplicación no puede crear dos transacciones.
- Las operaciones tienen una clave identificable para reintentos.
- POS utiliza una clave basada en el pago y las devoluciones una clave basada en la devolución.
- El ledger append-only se conserva, sin borrar ni modificar movimientos históricos.
- Verificación: créditos `2/2`; POS y devoluciones `45/45`; Pint limpio.

### Etapa 1.4: Sincronización

Estado: completada.

- La nube devuelve el resultado individual de cada evento.
- Solo los eventos aplicados, ignorados o duplicados válidos se marcan como procesados localmente.
- Un evento fallido permanece pendiente, conserva el error y recibe una próxima fecha de retry.
- Los eventos que no aparecen en una respuesta parcial también se consideran no confirmados.
- Se conserva compatibilidad con servidores antiguos que todavía devuelven solo contadores.
- Verificación: Sync backend `161/161` ejecutados, `1` skip existente.

### Etapa 1.5: Reservas Vencidas

Estado: completada.

- Las órdenes abiertas guardan hasta cuándo pueden conservar una reserva.
- Las reservas vencidas se liberan automáticamente mediante `inventory:expire-reservations`.
- La limpieza libera stock y unidades serializadas dentro de una transacción.
- La orden queda sin fecha de reserva después de procesarse.
- Ejecutar el comando nuevamente no crea otro movimiento.
- El scheduler ejecuta la limpieza cada cinco minutos.
- Verificación: POS backend `88/88`; reserva vencida `1/1`; Pint limpio.

## 9. Cómo Se Trabajará Cada Cambio

1. Definir el problema en términos de negocio.
2. Crear la prueba que demuestra el comportamiento esperado.
3. Ejecutar la prueba y confirmar que falla por el problema real.
4. Revisar permisos, empresas, sucursales, rollback y efectos secundarios.
5. Implementar el cambio más pequeño posible.
6. Ejecutar las pruebas nuevas y las existentes del módulo.
7. Ejecutar análisis de estilo, tipos y calidad.
8. Ejecutar pruebas de mutación cuando la herramienta esté disponible.
9. Ejecutar la suite completa cuando el cambio tenga impacto transversal.
10. Documentar el resultado y cualquier riesgo restante.

## 10. Regla Para Liberar Cambios

No se debe publicar una mejora si:

- Duplica ventas, pagos o movimientos.
- Permite que una empresa vea datos de otra.
- Deja el inventario diferente al historial.
- Pierde eventos de sincronización.
- Tiene pruebas nuevas fallando.
- No se entiende cómo recuperar una operación fallida.

## 11. Decisión Fiscal Pendiente

Antes de construir facturación fiscal se debe decidir con un contador:

- Si el sistema seguirá siendo POS comercial no fiscal.
- Si se conectará a una máquina fiscal.
- Si se utilizará forma libre.
- Si se integrará un proveedor de facturación digital.

La decisión determinará cómo se implementarán IVA, numeración, número de control, documentos, anulaciones, notas y contingencia.

## 12. Conclusión

El programa actual ya tiene una base útil para inventario, ventas y operación comercial. La prioridad inmediata es hacerlo confiable y evitar pérdidas por duplicados o fallos de conexión.

Después de estabilizar esas operaciones se puede construir la parte fiscal de forma ordenada, sin destruir las ventas existentes ni confundir tickets comerciales con facturas fiscales.
