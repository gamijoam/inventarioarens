# Contrato TDD de Promociones

## Alcance aprobado

- El CRUD de promociones vive en el cliente Administrativo.
- POS consulta promociones vigentes mediante un boton `Promociones`.
- Una venta puede aplicar una sola promocion; no se acumulan promociones.
- El precio promocional se configura en USD y puede ser menor, igual o mayor al total normal.
- Los combos pueden contener varios productos con cantidades configurables.
- Las promociones pueden aplicarse por identificador o por codigo.
- Las ventas pendientes conservan la promocion aplicada al momento de crearlas.
- Las promociones se sincronizan entre nube y nodos locales.

## Endpoints del contrato

```text
POST   /api/promotions
GET    /api/promotions
GET    /api/promotions/{promotion}
PATCH  /api/promotions/{promotion}
DELETE /api/promotions/{promotion}

GET    /api/pos/promotions/available
POST   /api/pos/checkouts
POST   /api/pos/orders/{posOrder}/payments
```

El checkout acepta como maximo una promocion seleccionada:

```json
{
  "promotion_id": 15,
  "promotion_code": "COMBO-50"
}
```

`promotion_id` se usa cuando el cajero selecciona una promocion desde el POS.
`promotion_code` se usa cuando el cajero introduce un codigo manualmente. Si se
envian ambos, deben referirse a la misma promocion.

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
pos.promotions.code
```

El permiso existente `pos.discount` continua controlando descuentos manuales y
no se reutiliza para promociones automaticas.

## Criterios TDD

### Fase 1: CRUD y disponibilidad

- crear combo USD con precio arbitrario;
- validar permisos de administracion;
- validar aislamiento entre tenants;
- listar solo promociones activas y vigentes;
- resolver prioridad sin acumulacion.
- mostrar y seleccionar la promoción desde el botón `Promociones` del POS;
- administrar combos desde la ruta Administrativa `/promotions`.

### Fase 2: Checkout

- aplicar por `promotion_id`;
- aplicar por `promotion_code`;
- aplicar `percent_discount` solo a los productos elegibles;
- aplicar `fixed_discount` como monto USD total distribuido entre los productos elegibles;
- aplicar `fixed_item_price` como precio USD por unidad de cada producto elegible;
- aplicar `free_item` con precio final cero para los productos elegibles;
- calcular conjuntos completos de `buy_x_get_y` con componentes `trigger` y `reward`;
- rechazar `buy_x_get_y` cuando falta la recompensa en el carrito;
- rechazar promocion de otro tenant, vencida o incompatible;
- registrar precio final y snapshot por linea;
- descontar stock y validar IMEIs;
- preservar montos base/locales y tasa.

### Fase 3: Pendientes y devoluciones

- conservar el precio promocional al completar una venta pendiente;
- recalcular si se modifica el carrito antes de crear la pendiente;
- devolver usando el snapshot historico.

### Fase 4: Sync

- emitir outbox al crear, actualizar, activar, desactivar o eliminar;
- aplicar eventos idempotentemente en el nodo local;
- mantener la promocion disponible offline hasta recibir cambios de nube.

## Tests iniciales

- `tests/Feature/Promotions/PromotionApiTest.php`
- `tests/Feature/POS/PosPromotionCheckoutTest.php`

Estos tests se escribieron antes de crear migraciones, modelos, servicios o
controladores. Los fallos actuales son esperados hasta implementar el contrato.
