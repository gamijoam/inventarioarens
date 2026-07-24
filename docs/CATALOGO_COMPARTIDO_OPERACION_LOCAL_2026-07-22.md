# Catalogo compartido con operacion local por tienda

Fecha: 2026-07-22

## Resumen ejecutivo

El enfoque actual de catalogo compartido por jerarquia permite que una sucursal vea productos del
grupo, pero no es suficiente para operar inventario real de forma segura. El problema no es solo de
permisos: el modelo fisico de stock, kardex, IMEIs y entradas usa foreign keys compuestas por
`tenant_id + product_id`, por lo que una tienda no puede operar correctamente contra un producto
que pertenece al tenant del grupo.

La recomendacion profesional es migrar a un modelo de **catalogo maestro compartido + copia
operativa local por tienda**. El grupo mantiene la fuente de verdad comercial y cada tienda mantiene
su propio producto local vinculado al maestro. Asi el usuario opera siempre dentro de su tienda,
con stock, almacenes, ventas, IMEIs y movimientos locales, sin entrar al grupo ni romper aislamiento.

## Bug detectado

### Sintoma

Desde una sucursal, por ejemplo `danubio-soledad`, al intentar trabajar con un producto creado en el
grupo `danubio`, aparecen errores al ver el detalle o al registrar entradas de inventario.

### Bug inmediato en validacion de entradas

Archivo afectado:

```txt
app/Modules/ProductEntries/Requests/StoreProductEntryRequest.php
```

El request usa `$tenantId` en la regla de `warehouse_id`, pero la variable no esta declarada:

```php
'items.*.warehouse_id' => ['required', Rule::exists('warehouses', 'id')->where('tenant_id', $tenantId)],
```

Ese bug puede causar `Undefined variable $tenantId` al registrar una entrada. La correccion inmediata
es declarar el tenant operativo actual:

```php
$tenantId = app(TenantManager::class)->require()->id;
$tenantIds = app(TenantManager::class)->sharedTenantIds();
```

Esto arregla el error directo, pero no resuelve el problema arquitectural.

## Causa raiz arquitectural

### 1. Producto compartido visible no significa producto operable

El trait `BelongsToTenantHierarchy` permite que un spinoff lea registros del tenant actual y del grupo
padre. En teoria esto deja a la tienda ver el catalogo del grupo.

Archivo:

```txt
app/Support/Tenancy/Concerns/BelongsToTenantHierarchy.php
```

La lectura jerarquica es util para mostrar catalogos, pero no basta para operaciones fisicas como:

- entradas de inventario;
- salidas;
- ventas POS;
- IMEIs/seriales;
- stock por almacen;
- kardex;
- conteos fisicos;
- traslados.

### 2. Las policies aun validan pertenencia exacta

`ProductPolicy::view()`, `update()` y `delete()` llaman a `ownsResource()`, que exige que
`product.tenant_id` sea exactamente el tenant actual.

Archivo:

```txt
app/Modules/Products/Policies/ProductPolicy.php
```

Problema:

```php
return $tenantId !== null && (int) $product->tenant_id === (int) $tenantId;
```

Entonces una sucursal puede ver el producto en algun listado por el scope jerarquico, pero fallar con
403 al abrir detalle o ejecutar acciones porque el producto realmente pertenece al grupo.

### 3. Inventario exige mismo tenant para almacen y producto

`InventoryMovementService::validateOperation()` exige que tanto el almacen como el producto
pertenezcan al tenant actual.

Archivo:

```txt
app/Modules/Inventory/Services/InventoryMovementService.php
```

Problema:

```php
$this->assertSameTenant($warehouse);
$this->assertSameTenant($product);
```

Para una sucursal:

- `warehouse.tenant_id = danubio-soledad`;
- `product.tenant_id = danubio`;
- tenant actual = `danubio-soledad`.

El almacen pasa, el producto falla.

### 4. La base de datos refuerza el aislamiento por tenant

Varias tablas operativas tienen foreign keys compuestas con `tenant_id + product_id`. Esto es bueno
para seguridad multi-tenant, pero significa que no se puede crear stock local para un producto de otro
tenant sin cambiar el modelo.

Tablas afectadas:

- `stock_balances`;
- `stock_movements`;
- `product_units`;
- `product_entry_items`.

Ejemplo:

```txt
stock_balances.tenant_id + stock_balances.product_id
  -> products.tenant_id + products.id
```

Si la fila de stock pertenece a `danubio-soledad`, la fila de producto tambien debe pertenecer a
`danubio-soledad`. Un `product_id` del grupo `danubio` no cumple esa FK compuesta.

## Por que no conviene solo dar mas permisos

Una solucion rapida seria permitir que la sucursal vea y edite productos del grupo cambiando policies.
Eso quita algunos 403, pero deja problemas mas graves:

- stock local apuntando a producto de otro tenant;
- movimientos de kardex cruzados;
- IMEIs con ownership ambiguo;
- entradas y ventas mezclando tenant operativo con tenant maestro;
- riesgo de romper aislamiento multi-tenant;
- cambios futuros mas dificiles en sync, reportes y auditoria.

Por eso, aumentar permisos seria un parche, no una arquitectura estable.

## Modelo recomendado

### Catalogo maestro compartido

El grupo mantiene la fuente de verdad comercial:

- producto maestro;
- marca;
- categorias;
- tags;
- descripcion comercial;
- imagenes;
- precio base;
- listas de precio;
- metodos de pago;
- tipos y valores de tasa.

### Copia operativa local por tienda

Cada tienda mantiene su propio producto local, vinculado al maestro:

- `products.tenant_id = tienda_id`;
- `products.catalog_product_id = producto_maestro_id` o equivalente;
- mismo SKU/codigo/barcode sincronizado desde el maestro;
- stock local por tienda;
- IMEIs locales por tienda;
- kardex local por tienda;
- ventas/POS locales;
- almacenes y cajas locales.

Visualmente el usuario ve un solo catalogo compartido. Internamente, cada tienda opera contra sus IDs
locales.

## Resultado esperado para el usuario

Caso: grupo `danubio` con sucursal `danubio-soledad`.

1. El administrador del grupo crea `iPhone 13` una vez.
2. El sistema crea o sincroniza una copia local del producto en `danubio-soledad`.
3. El usuario de `danubio-soledad` entra a su empresa y ve `iPhone 13` como parte de su catalogo.
4. Al registrar entrada, el backend usa el `product_id` local de `danubio-soledad`.
5. `stock_balances`, `stock_movements`, `product_units` y `product_entry_items` quedan con
   `tenant_id = danubio-soledad`.
6. El grupo puede seguir actualizando datos maestros, y esos cambios se propagan a las copias.

## Cambios de backend propuestos

### Fase 0 - Hotfix inmediato

Objetivo: desbloquear el bug reportado sin fingir que resuelve la arquitectura completa.

- Declarar `$tenantId` en `StoreProductEntryRequest`.
- Revisar otros requests donde se use `$tenantId` sin inicializar.
- Agregar test de entrada en tenant normal para evitar regresion.

### Fase 1 - Nuevo vinculo maestro/local

Objetivo: permitir que un producto local apunte a un producto maestro compartido.

Posibles campos:

```txt
products.catalog_product_id nullable
products.catalog_origin_tenant_id nullable
products.catalog_sync_enabled boolean default true
products.local_overrides json nullable
```

Reglas:

- producto maestro del grupo tiene `catalog_product_id = null`;
- producto local de tienda tiene `catalog_product_id = id_del_maestro`;
- `tenant_id` del producto local siempre es el tenant de la tienda;
- SKU/barcode deben seguir siendo unicos por tenant;
- la vinculacion debe tener indice unico por tienda y maestro.

Indice sugerido:

```txt
unique(tenant_id, catalog_product_id) where catalog_product_id is not null
```

### Fase 2 - Servicio de propagacion de catalogo

Crear un servicio dedicado, por ejemplo:

```txt
App\Modules\Products\Services\SharedCatalogPropagationService
```

Responsabilidades:

- cuando el grupo crea un producto, crear copias locales en cada spinoff activo;
- cuando se crea una nueva sucursal, clonar el catalogo maestro existente;
- cuando el grupo actualiza campos maestros, propagar cambios a copias locales;
- respetar overrides locales si se habilitan en el futuro;
- ejecutar en transaccion para cambios pequenos;
- considerar jobs para catalogos grandes.

### Fase 3 - Separar campos maestros y campos operativos

Campos que deberian venir del maestro:

- SKU;
- barcode;
- nombre;
- descripcion;
- descripcion larga;
- unidad de medida;
- marca;
- categorias;
- tags;
- imagenes;
- tracking type;
- precio base/listas.

Campos que deben quedar locales por tienda:

- stock;
- average_cost si representa costo real operativo local;
- min_stock;
- max_stock;
- reorder_quantity;
- activo/inactivo local si se desea permitir que una tienda oculte un producto;
- ubicaciones;
- IMEIs/seriales.

Decision pendiente: definir si `min_stock`, `max_stock` y `reorder_quantity` son maestros o locales.
Para cadenas de tiendas normalmente conviene que sean locales porque cada sucursal tiene rotacion
distinta.

### Fase 4 - Adaptar APIs

La API debe devolver productos operativos locales para la tienda actual.

Reglas:

- `GET /api/products` en una sucursal devuelve productos locales vinculados al maestro;
- `GET /api/products/{product}` abre el producto local;
- entradas, salidas, POS y traslados reciben IDs locales;
- el usuario no necesita saber que existe `catalog_product_id`;
- endpoints administrativos del grupo pueden mostrar estado de propagacion por tienda.

### Fase 5 - Migracion de datos existentes

Para grupos ya existentes:

1. Detectar productos del tenant grupo.
2. Para cada spinoff activo, crear copia local si no existe.
3. Copiar campos maestros.
4. Vincular copia local con `catalog_product_id`.
5. Reasignar operaciones futuras al producto local.
6. No modificar movimientos historicos sin una migracion especifica y auditada.

Si ya existen movimientos locales usando IDs cruzados, deben revisarse antes de migrar porque podrian
estar bloqueados por FK o haber quedado a medio camino.

### Fase 6 - Frontend

El frontend debe mantener la experiencia simple:

- el usuario de tienda ve productos como propios;
- no debe entrar al tenant grupo para operar;
- el badge puede indicar `Sucursal`, pero no debe bloquear operaciones normales;
- si un producto viene del catalogo compartido, se puede mostrar una etiqueta discreta como
  `Compartido`;
- los formularios operativos siempre envian `product_id` local;
- el panel de grupo puede mostrar opciones de sincronizar/propagar catalogo.

## Tests obligatorios

### Backend

- Crear producto en grupo y verificar que se crea copia local en cada spinoff.
- Crear spinoff nuevo y verificar que recibe el catalogo maestro existente.
- Registrar entrada desde spinoff usando producto visible y confirmar:
  - `stock_balances.tenant_id = spinoff_id`;
  - `stock_movements.tenant_id = spinoff_id`;
  - `product_entry_items.tenant_id = spinoff_id`;
  - `product_units.tenant_id = spinoff_id` para serializados.
- Vender desde POS en spinoff y descontar solo stock local.
- Verificar que otra sucursal no ve ni descuenta ese stock.
- Verificar que updates del grupo se propagan a copias locales.
- Verificar permisos: usuario de sucursal puede operar producto local, pero no administrar catalogo
  maestro si no es Owner del grupo.

### Frontend

- Listado de productos en sucursal muestra catalogo sincronizado.
- Entrada de inventario en sucursal usa producto local y termina sin 403.
- POS en sucursal puede buscar y vender producto compartido.
- Vista de grupo muestra catalogo maestro y estado de propagacion.

## Riesgos y decisiones pendientes

### Volumen de datos

Duplicar productos por tienda aumenta filas. Ejemplo: 500 productos x 7 tiendas = 3500 productos.
Ese volumen es normal y manejable para PostgreSQL. Es preferible a romper FKs o mezclar tenants.

### Overrides locales

Se debe decidir si una tienda puede modificar ciertos campos sin que el grupo los sobrescriba.
Recomendacion inicial:

- no permitir overrides al principio;
- permitir solo campos locales operativos (`min_stock`, `max_stock`, activo local) si hace falta;
- dejar overrides comerciales para una fase posterior.

### Precios

Si las listas de precio son compartidas por grupo, hay dos opciones:

- mantener listas maestras y copiarlas por tienda junto con productos;
- mantener listas compartidas, pero resolver precios por `catalog_product_id`.

Recomendacion: resolver precios contra el producto local, con propagacion desde el maestro, para que
POS y restricciones de metodo de pago no tengan que mezclar tenants.

### Sync local-nube

El sync debe tratar cada tienda como operativa independiente. Los eventos de stock, ventas, caja e
IMEIs deben seguir saliendo con el tenant de la tienda. La propagacion de catalogo desde grupo a tienda
debe generar eventos o snapshots claros para que el nodo local reciba sus productos locales.

## Decision recomendada

Adoptar **catalogo maestro compartido + producto local operativo vinculado** como modelo objetivo.

No continuar ampliando `BelongsToTenantHierarchy` para que productos del grupo sean operados directo
por las tiendas. Ese enfoque sirve para lectura, pero no para inventario fisico con FKs compuestas,
auditoria, IMEIs, POS y sync.

## Primeros pasos concretos

1. Aplicar hotfix de `$tenantId` en `StoreProductEntryRequest`.
2. Crear migracion para campos de vinculo maestro/local en `products`.
3. Crear `SharedCatalogPropagationService`.
4. Agregar tests de propagacion y entrada desde spinoff.
5. Cambiar el flujo de creacion/actualizacion de productos del grupo para propagar copias locales.
6. Ajustar frontend para consumir siempre el producto local en operaciones.
7. Mantener `SharedCatalogWriteGuard` solo para administrar el maestro, no para bloquear operacion local.
