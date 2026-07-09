# POS - Pagos mixtos, listas de precio y tasas

## Objetivo

Dejar una regla clara para el cobro en el POS cuando una venta mezcla productos, monedas, listas de precio, tasas y metodos de pago distintos.

## Regla operativa

- La venta mantiene una base contable en USD.
- Cada producto agregado al carrito conserva su propia foto de precio:
  - lista de precio usada;
  - moneda de venta;
  - precio unitario;
  - tipo de tasa;
  - tasa vigente;
  - equivalencia en Bs cuando exista.
- El total principal del carrito se muestra en USD.
- El equivalente en Bs se muestra cuando las lineas tienen tasa disponible.
- Si un producto no tiene tasa disponible, el POS debe mostrarlo como una alerta visual, no como un calculo silencioso.

## Pagos

- Un pago en USD cubre directamente el saldo base en USD.
- Un pago en VES requiere seleccionar una tasa de cobro activa.
- La tasa del pago no tiene que ser igual a todas las tasas de los productos del carrito.
- Si una venta esta expresada en USD, una parte puede recibirse en bolivares usando una tasa de cobro distinta a la tasa original del producto.
- La tasa elegida en el pago se guarda como respaldo para auditoria y cuadre de caja.
- El sistema permite pagos mixtos:
  - USD + VES;
  - efectivo USD + pago movil Bs;
  - transferencia Bs + efectivo Bs;
  - pagos parciales para completar el saldo.

## Ejemplos

### Producto de USD 50 pagado mixto

Si el cliente paga USD 40 y el resto en bolivares:

1. El POS registra USD 40 como pago directo.
2. El faltante queda en USD 10.
3. El cajero selecciona la tasa de cobro en Bs.
4. El POS calcula el monto en Bs requerido para cubrir esos USD 10.
5. El sistema valida caja, metodo de pago, moneda, tasa y stock antes de confirmar.

Ejemplo numerico validado por pruebas:

- Total de la venta: USD 50.
- Pago 1: USD 40 en efectivo.
- Pago 2: Bs 6.000 con tasa Paralelo 600.
- Resultado contable: Bs 6.000 / 600 = USD 10.
- Total cubierto: USD 40 + USD 10 = USD 50.
- La venta queda pagada, no pendiente.

### Productos con tasas distintas

Si una venta contiene un producto calculado con BCV y otro con Paralelo:

1. Cada item conserva su tasa original para auditoria.
2. El total se consolida en USD.
3. Si el cliente paga en Bs, el cajero selecciona una tasa de cobro para ese pago.
4. El sistema no obliga a separar la venta por tasa, porque el pago tiene su propia tasa registrada.

## Validaciones que deben mantenerse

- No vender productos sin stock.
- No vender seriales/IMEI ya reservados o vendidos.
- No cobrar con una caja cerrada.
- No cobrar con una caja de otro cajero.
- No aceptar metodos de pago desactivados.
- No aceptar pagos en una moneda no permitida por el metodo seleccionado.
- Mantener referencias obligatorias cuando el metodo de pago lo requiera.
- Las ventas POS pagadas deben aparecer en el portal web administrativo dentro del periodo del dia, siempre que la sincronizacion local-nube haya subido la orden y sus pagos.

## Interfaz de cobro

- La ventana de cobro debe ser compacta y pensada para cajeros.
- No se deben mostrar palabras tecnicas como backend, Laravel o estados internos.
- Las acciones principales deben ser directas: saldo exacto, limpiar, agregar, eliminar pago y confirmar venta.
- El estado interno del pago se mantiene por sistema, pero el cajero solo ve el pago como registrado o pendiente cuando aplique.
- El boton Volver al panel del POS debe permitir salir al centro de modulos aunque el buscador mantenga foco automatico para escaner.

### Rediseño aplicado

- La cabecera muestra el contexto de lista y caja en una sola linea clara: precio/lista, caja y accion esperada.
- El panel izquierdo queda dedicado a recibir pagos: atajos visuales, forma de pago, moneda, monto, referencia y acciones.
- El panel derecho queda dedicado al resumen: total a cobrar, pagos agregados, estado vacio visual y tarjetas de pagado, faltante y vuelto.
- El pie mantiene solo atajos operativos y botones globales: cancelar y confirmar venta.
- El pie de acciones se ubica compacto en el panel derecho para no quitar altura al formulario Recibir pago.
- El texto visible se mantiene en español y orientado al cajero, sin detalles tecnicos de implementacion.
- El pie y las tarjetas de resumen se mantienen compactos para darle mas altura util al panel Recibir pago.

- El formulario Recibir pago usa campos, atajos y separaciones compactas para que la referencia quede visible cuando el metodo de pago la exige.

## Pendiente futuro

- Mostrar un resumen de equivalencias por tasa cuando la venta mezcle muchos tipos de cambio.
- Agregar reglas administrativas para restringir que una lista de precio solo acepte ciertos metodos de pago.
- Mejorar reportes de cuadre para separar recibido en USD, recibido en Bs y equivalente contable en USD.
