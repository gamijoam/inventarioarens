# Login Operativo Y Publicacion Electron

## Objetivo

Este documento conserva el contexto del cambio que separa el acceso rapido de un cajero del acceso
de un vendedor que prepara pedidos desde una tablet.

## Redireccion Despues Del Login

La decision vive en:

- `frontend/src/auth/postLoginRoute.ts`
- `frontend/src/auth/LoginPage.tsx`
- `frontend/src/routes/login.tsx`

La aplicacion decide por los permisos efectivos que devuelve el backend para la empresa seleccionada,
no por el nombre visible del rol. Esto permite clonar o renombrar un perfil sin romper el flujo.

Reglas actuales:

| Acceso efectivo | Pantalla inicial |
| --- | --- |
| Usuario administrativo o con acceso de usuarios, roles o configuracion | `/dashboard` |
| `pos.view` + `pos.checkout` + `pos.orders.hold` | `/pos/armar` |
| `pos.view` + `pos.checkout`, sin `pos.orders.hold` | `/pos` |
| Sin permisos suficientes para operar POS | `/dashboard` |

`pos.orders.hold` es la diferencia operativa entre el vendedor que prepara un pedido y el cajero
que cobra directamente. `sales.create` no se usa como criterio de redireccion porque ambos perfiles
pueden necesitar crear ventas.

La ruta de respaldo es `/dashboard`. Los usuarios administrativos no se envian al POS aunque tengan
permisos de POS, para conservar una entrada de trabajo coherente.

## Permisos Y Sincronizacion Local

El catalogo de permisos se define en `app/Support/Permissions/BasePermissions.php`. El rol
predeterminado `Vendedor` debe incluir `pos.view`, `pos.checkout` y `pos.orders.hold`; un rol clonado
debe recibir esos mismos permisos si se desea el flujo `/pos/armar`.

Los roles y permisos no viajan por sincronizacion. En una instalacion local preparada antes de un
cambio de permisos, se debe volver a preparar el tenant o volver a vincularlo para sembrar el
catalogo actualizado. La sincronizacion de datos no sustituye esa preparacion.

## Pruebas Del Cambio

El contrato frontend esta cubierto por:

- `frontend/src/auth/postLoginRoute.test.ts`
- `frontend/src/auth/LoginPage.test.tsx`

Verificacion realizada:

- Vitest: 2 archivos, 6 pruebas exitosas.
- TypeScript: `npm run typecheck` exitoso.

## Publicacion De Los Clientes Electron

La version actual se consulta en `frontend/package.json`. Cada instalador Electron debe publicarse
con una version mayor a la instalada; `electron-updater` no reinstala una version igual.

El workflow `.github/workflows/release.yml` publica canales independientes:

- `admin` para administracion.
- `pos` para caja.
- `technician` para soporte local y sincronizacion.

Una subida a `main` ejecuta CI, pero no publica instaladores. Para publicar:

1. Subir el campo `version` de `frontend/package.json`.
2. Commitear y hacer push a `main`.
3. Abrir Actions, elegir `Release Electron client` y pulsar `Run workflow`.
4. Seleccionar el cliente (`admin`, `pos` o `technician`).
5. Repetir para los otros clientes si comparten la misma instalacion local.

Cada ejecucion crea un release `v<version>-<cliente>`, junto con el instalador, el `.blockmap` y el
archivo de actualizacion del canal.

El disparo por tag sin elegir cliente usa el valor por defecto `technician` del workflow. Para POS o
Admin se debe usar el formulario manual de Actions y seleccionar el cliente.

Los clientes anteriores al soporte de `electron-updater` necesitan una instalacion manual inicial.
Despues de ese bootstrap, las siguientes versiones se descargan desde GitHub Releases y se instalan
al reiniciar.

## Checklist De Diagnostico

- Si un vendedor entra al dashboard: revisar `pos.view`, `pos.checkout` y `pos.orders.hold` en la
  empresa seleccionada.
- Si un cajero entra a `/pos/armar`: quitar `pos.orders.hold` de su perfil.
- Si los permisos parecen correctos en la nube pero no en Electron: volver a preparar o vincular el
  tenant local; los permisos no se sincronizan como datos.
- Si la interfaz no cambia tras publicar: confirmar que la version instalada es menor y que se
  publico el canal correcto.
- Si se actualizo la base SQLite con un cliente nuevo: publicar Admin, POS y Technician con la misma
  version cuando los tres clientes comparten el runtime local.

## Fuentes Relacionadas

- `docs/ELECTRON_UPDATES_AND_TECHNICIAN.md`
- `docs/GRAPHIFY_CONTEXT_MAP.md`
- `.github/workflows/release.yml`
- `frontend/src/features/pos-armar/ArmOrderScreen.tsx`
- `frontend/src/features/pos/PosTerminal.tsx`
