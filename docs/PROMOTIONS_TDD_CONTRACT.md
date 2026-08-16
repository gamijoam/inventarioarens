# Contrato TDD de Promociones

## Alcance aprobado

- El CRUD de promociones vive en el cliente Administrativo.
- POS consulta promociones vigentes mediante un boton `Promociones`.
- Una venta puede aplicar como maximo una promocion de factura y multiples instancias de combos.
- Los combos se calculan antes de la promocion de factura.
- Cada instancia de combo conserva un `instance_uuid` para evitar mezclar lineas iguales.
- El precio promocional se configura en USD y puede ser menor, igual o mayor al total normal.
- Los combos pueden contener varios productos con cantidades configurables.
- Las promociones pueden aplicarse por identificador o por codigo en los flujos que lo habiliten.
- Las ventas pendientes conservan la promocion aplicada al momento de crearlas.
- Las promociones se sincronizan entre nube y nodos locales.
- Una promocion puede aceptar cualquier moneda de pago o exigir que el pago completo sea en VES.
- Los descuentos de factura (`percent_discount` y `fixed_discount`) se separan de los combos: no
  requieren productos y se aplican al total del ticket.
- Los combos (`fixed_bundle_price` y `buy_x_get_y`) requieren componentes y cargan sus lineas al
  ticket. Las ofertas por producto (`fixed_item_price` y `free_item`) tienen dominio separado y se
  aplican por linea normal seleccionada; nunca modifican lineas con `combo_instance_uuid`.
- Las ofertas por producto pueden coexistir con combos y una promocion de factura. La promocion de
  factura se calcula al final sobre el resultado de las lineas elegibles.
- En `/pos/armar`, el vendedor solicita la promocion de factura al crear la orden pendiente. Caja
  debe elegir `validate` o `reject` antes de capturar el pago.

## Endpoints del contrato

```text
GET    /api/invoice-promotions
POST   /api/invoice-promotions
GET    /api/invoice-promotions/{promotion}
PATCH  /api/invoice-promotions/{promotion}
DELETE /api/invoice-promotions/{promotion}

GET    /api/combos
POST   /api/combos
GET    /api/combos/{promotion}
PATCH  /api/combos/{promotion}
DELETE /api/combos/{promotion}

GET    /api/product-offers
POST   /api/product-offers
GET    /api/product-offers/{promotion}
PATCH  /api/product-offers/{promotion}
DELETE /api/product-offers/{promotion}

GET    /api/promotions                  # compatibilidad legacy
GET    /api/promotions/{promotion}      # compatibilidad legacy
PATCH  /api/promotions/{promotion}      # compatibilidad legacy
DELETE /api/promotions/{promotion}      # compatibilidad legacy

GET    /api/pos/invoice-promotions
GET    /api/pos/combos
GET    /api/pos/product-offers
GET    /api/pos/promotions/available     # compatibilidad legacy
POST   /api/pos/checkouts
POST   /api/pos/orders/{posOrder}/payments
```

El checkout acepta una promocion de factura y una lista de instancias de combos:

```json
{
  "invoice_promotion_id": 15,
  "combo_applications": [
    {
      "promotion_id": 20,
      "instance_uuid": "combo-01",
      "sets": 2
    }
  ],
  "product_offer_applications": [
    { "promotion_id": 30, "item_index": 1 }
  ],
  "items": [
    { "product_id": 10, "combo_instance_uuid": "combo-01" }
  ]
}
```

`invoice_promotion_id` identifica el descuento de factura seleccionado desde el POS. Las
instancias de combo se identifican con `promotion_id` + `instance_uuid`; las lineas que pertenecen
a una instancia deben enviar el mismo `combo_instance_uuid`. Las ofertas por producto se vinculan
por `item_index` y solo son validas cuando esa linea no tiene `combo_instance_uuid`.

Las ordenes pendientes conservan el snapshot en `sale.promotion_applications`. Al cobrar una orden
con una promocion de factura solicitada, caja debe enviar `invoice_promotion_action` con valor
`validate` o `reject`; el campo es obligatorio en ese caso.

La configuracion opcional `payment_currency` acepta `ANY` o `VES`. Cuando vale
`VES`, todos los pagos capturados o pendientes de la orden deben estar en
bolivares; los pagos USD o mixtos se rechazan.

## Beneficios previstos

```text
percent_discount
fixed_discount
fixed_item_price
fixed_bundle_price
free_item
buy_x_get_y
```

El primer corte TDD implementa `fixed_bundle_price`, `percent_discount`,
`fixed_discount`, `fixed_item_price`, `free_item` y `buy_x_get_y`: combos,
descuentos, precios unitarios, artículos gratis y recompensas por conjuntos de
compra.
Los tipos restantes mantienen el contrato, pero requieren sus propias pruebas y
motor de evaluación antes de habilitarse en producción.

## Precio de combo

El motor calcula el precio normal del conjunto y lo compara con el precio USD
configurado. El precio configurado siempre es la verdad comercial. El resultado
puede ser:

- descuento, si el precio promocional es menor;
- precio igual, si ambos son iguales;
- ajuste positivo, si el precio promocional es mayor.

El precio final del combo se distribuye entre sus `sale_items` proporcionalmente
al precio normal de cada componente. El remanente de redondeo se aplica a la
ultima linea. La venta conserva el snapshot de la promocion, su codigo, nombre,
tipo de beneficio y precio configurado; las devoluciones no recalculan la
promocion vigente.

## Permisos

```text
promotions.view
promotions.create
promotions.update
promotions.delete
pos.promotions.view
pos.promotions.apply
pos.promotions.request
pos.promotions.validate
pos.promotions.code
```

El permiso existente `pos.discount` continua controlando descuentos manuales y
no se reutiliza para promociones automaticas.
`pos.promotions.request` controla la solicitud de descuentos de factura en
`/pos/armar`; `pos.promotions.validate` controla la validacion o rechazo en caja.

## Criterios TDD

### Fase 1: CRUD y disponibilidad

- crear combo USD con precio arbitrario;
- validar permisos de administracion;
- validar aislamiento entre tenants;
- listar solo promociones activas y vigentes;
- resolver prioridad sin acumulacion.
- mostrar y seleccionar la promoción desde el botón `Promociones` del POS;
- administrar combos desde la ruta Administrativa `/promotions`.
- administrar por separado descuentos de factura, combos y ofertas por producto.

### Fase 2: Checkout

- aplicar una promocion de factura y multiples instancias de combos;
- conservar el snapshot de la promocion seleccionada;
- aplicar `percent_discount` solo a los productos elegibles;
- aplicar `fixed_discount` como monto USD total distribuido entre los productos elegibles;
- cuando un descuento no tiene componentes, aplicarlo a todas las lineas de la factura;
- aplicar `fixed_item_price` como precio USD por unidad de cada producto elegible;
- aplicar `free_item` con precio final cero para los productos elegibles;
- aplicar ofertas por producto mediante `product_offer_applications` a lineas normales;
- rechazar ofertas por producto dirigidas a lineas de combos;
- calcular conjuntos completos de `buy_x_get_y` con componentes `trigger` y `reward`;
- rechazar `buy_x_get_y` cuando falta la recompensa en el carrito;
- rechazar promocion de otro tenant, vencida o incompatible;
- rechazar una promocion restringida a VES cuando el pago es USD o mixto;
- registrar precio final y snapshot por linea;
- descontar stock y validar IMEIs;
- preservar montos base/locales y tasa.

### Fase 3: Pendientes y devoluciones

- conservar el precio promocional al completar una venta pendiente;
- solicitar la promocion en `/pos/armar` y validarla o rechazarla explicitamente en caja;
- exponer el snapshot y sus asignaciones por linea al recuperar pendientes;
- recalcular si se modifica el carrito antes de crear la pendiente;
- devolver usando el snapshot historico.

### Fase 4: Sync

- emitir outbox al crear, actualizar, activar, desactivar o eliminar;
- aplicar eventos idempotentemente en el nodo local;
- mantener la promocion disponible offline hasta recibir cambios de nube.
- sincronizar la condicion de moneda de pago.

## Tests iniciales

- `tests/Feature/Promotions/PromotionApiTest.php`
- `tests/Feature/POS/PosPromotionCheckoutTest.php`

Estos tests se escribieron antes de crear migraciones, modelos, servicios o
controladores. Los fallos actuales son esperados hasta implementar el contrato.
