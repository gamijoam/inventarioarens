# Bootstrap inicial selectivo por grupo

Fecha: 2026-08-15

## Objetivo

Una instalacion local nueva debe poder seleccionar varias empresas de un mismo
grupo, descargar su informacion inicial en una sola operacion controlada y
comenzar despues la sincronizacion incremental normal.

Ejemplo: una instalacion de Oscar Cell puede seleccionar:

- `oscar-cell`
- `oscarcell-yaracall`
- `oscarcell-chichiriviche`
- `oscarcell-tucacas-grande`
- `oscarcell-tucacas-peque`

La seleccion se limita siempre a los tenants del grupo autorizado por el
codigo de vinculacion. Nunca se acepta un tenant externo enviado por el
cliente.

## Flujo operativo

1. El Owner genera un codigo de vinculacion grupal.
2. El instalador consulta la vista previa del codigo y muestra las empresas
   disponibles.
3. El tecnico selecciona las empresas que se instalaran localmente.
4. El codigo se redime una sola vez indicando los IDs remotos seleccionados.
5. La nube emite un token independiente por cada empresa seleccionada.
6. Para cada token, el local solicita un paquete de bootstrap.
7. La nube fija un punto de corte y prepara un snapshot de esa empresa.
8. El local importa el paquete en SQLite, conserva los mapeos remoto-local y
   valida que no existan eventos fallidos.
9. El local confirma el bootstrap. La nube marca el snapshot como entregado y
   avanza el cursor del nodo.
10. El Motor Local comienza el worker incremental por empresa.

## Seguridad

- El codigo grupal sirve para la instalacion inicial y es de un solo uso.
- El bootstrap usa el token independiente de cada tenant.
- El token de una empresa no puede solicitar el bootstrap de otra.
- El token grupal no se usa como credencial permanente del worker.
- El paquete no contiene passwords, hashes de tokens ni permisos globales.
- Los permisos locales se siembran desde `BasePermissions`; no se copian por
  sync.

## Consistencia y punto de corte

El servidor registra `snapshot_cutoff_id` antes de generar el paquete. Los
cambios ocurridos despues de ese corte permanecen en `sync_outbox` y llegan por
el flujo incremental.

El bootstrap solo confirma sus propios eventos de snapshot. Los eventos
operativos posteriores no se eliminan ni se marcan como procesados.

Si la importacion local falla, el bootstrap no se confirma y puede reintentarse
sin activar el worker sobre una empresa incompleta.

## Alcance inicial del paquete

El paquete reutiliza el contrato de la foto inicial existente e incluye los
datos necesarios para operar la empresa local:

- sucursales y almacenes;
- tipos y tasas de cambio;
- metodos de pago;
- marcas, categorias y tags;
- politicas de garantia y proveedores;
- listas y precios;
- productos, promociones y clientes;
- movimientos y saldos iniciales de inventario;
- unidades serializadas;
- imagenes y variantes;
- cajas registradoras.

Las ventas, cobros, compras y otros historiales append-only que no formen parte
de la foto inicial permanecen en el flujo incremental y no se reconstruyen
mediante un dump de PostgreSQL.

## Por que no se usa un dump completo

La nube usa PostgreSQL y la instalacion local usa SQLite. Un `pg_dump` completo
incluye todos los tenants, usuarios, tokens y estados internos, y no respeta
los mapeos remoto-local. El bootstrap es una exportacion tenant-scoped y
portable, no una restauracion de la base de datos completa.

## Contrato

Rutas publicas protegidas por codigo temporal:

```text
POST /api/sync/pairing-codes/preview
POST /api/sync/pairing-codes/redeem
```

Rutas autenticadas por tenant y token independiente:

```text
POST /api/sync/bootstrap
POST /api/sync/bootstrap/{session}/complete
```

El endpoint de bootstrap devuelve la metadata de la sesion, el punto de corte
y los eventos del snapshot en un paquete. El endpoint `complete` es idempotente
y solo puede completar una sesion perteneciente al tenant autenticado.

## Criterios de aceptacion

- Un codigo grupal permite seleccionar solo empresas del grupo.
- Un tenant externo produce `403` o `422` y no recibe token.
- El bootstrap de una empresa no expone datos de otra empresa.
- El paquete se importa en SQLite con timestamps y mapeos validos.
- Un fallo de importacion deja la sesion sin completar y permite reintento.
- Los cambios posteriores al punto de corte llegan por `sync_outbox`.
- Al completar el bootstrap se activa la configuracion normal del worker por
  empresa.
