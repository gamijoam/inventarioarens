# Sincronizacion de productos web con garantias

## Motivo

Al crear un producto desde el portal web, el evento llegaba al local pero podia fallar si el producto tenia una politica de garantia asociada. La causa era que el evento copiaba el `warranty_policy_id` de la nube, pero los IDs internos no necesariamente coinciden con los IDs de cada instalacion local.

## Regla operativa

La sincronizacion no debe depender de IDs internos entre nube y local para catalogos relacionados. Cuando un producto referencia una garantia, una tasa, una lista de precio, un almacen o una caja, el evento debe incluir un identificador estable:

- codigo, cuando exista;
- nombre normalizado, cuando no exista codigo;
- datos minimos para recrear el catalogo local si hace falta.

## Implementacion aplicada

- Los eventos de producto ahora incluyen datos de la politica de garantia: nombre, dias, tipo de cobertura, condiciones y estado.
- El aplicador local resuelve la garantia por empresa y nombre.
- Si la garantia no existe localmente, la crea con los datos recibidos.
- Si el ID recibido existe localmente para esa misma empresa, se usa; si no existe, se ignora el ID de nube y se resuelve por nombre.

## Confirmacion de eventos

El worker local ya no confirma eventos a la nube apenas los descarga. Ahora el flujo correcto es:

1. Descargar evento de la nube.
2. Guardarlo en `sync_inbox` local.
3. Aplicarlo localmente.
4. Confirmarlo a la nube solo si quedo `applied` o `ignored`.

Si el evento falla localmente, queda en `sync_inbox` con estado `failed` y no se confirma a la nube. Esto permite diagnosticarlo y reintentarlo sin perder el cambio.

## Caso validado

Producto creado en web:

- nombre: `PRODUCTO VALENCIA CENTRAL`;
- origen: portal web nube;
- destino: local Demo Valencia;
- problema: garantia con ID distinto entre nube y local.

El correctivo permite que el producto se cree localmente aunque la politica de garantia tenga otro ID en la instalacion local.

## Pruebas especificas

Se agregaron pruebas para validar:

- creacion local de producto con garantia recibida por nombre y no por ID de nube;
- no confirmar a la nube un evento que falla al aplicarse localmente.
