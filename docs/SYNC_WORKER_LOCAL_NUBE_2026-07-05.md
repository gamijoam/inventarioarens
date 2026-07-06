# Worker de sincronizacion local-nube

Fecha: 2026-07-05

## Objetivo

Se agrego el primer worker local para ejecutar ciclos de sincronizacion entre una base local y el API de la nube.

Esta fase se enfoca en transporte confiable:

- registrar el nodo local en la base local y en la nube;
- subir eventos pendientes de `sync_outbox` local hacia la nube;
- bajar eventos pendientes de la nube hacia `sync_inbox` local;
- confirmar a la nube los eventos recibidos;
- mantener `sync_states` para saber el ultimo intento por nodo y direccion.
- aplicar automaticamente eventos de configuracion comercial soportados.

## Comando agregado

```powershell
php artisan sync:run empresa-demo --node=LOCAL-01 --name="Local principal" --cloud-url=https://dominio.com/api --token=TOKEN
```

Opciones:

- `tenant`: slug de la empresa local.
- `--node`: codigo unico del nodo local.
- `--name`: nombre visible del nodo.
- `--cloud-url`: URL base del API de la nube.
- `--token`: token Bearer del API de la nube.
- `--limit`: cantidad maxima de eventos por ciclo. Maximo 200.
- `--push-only`: solo envia eventos locales.
- `--pull-only`: solo recibe eventos desde la nube.
- `--no-apply`: recibe eventos pero no los aplica automaticamente.

Tambien se puede configurar:

```env
SYNC_CLOUD_URL=
SYNC_CLOUD_TOKEN=
```

## Flujo local hacia nube

1. El worker busca eventos `pending` en `sync_outbox` local.
2. Los envia a `POST /api/sync/events/push`.
3. Si la nube responde correctamente, marca esos eventos locales como `processed`.
4. Registra estado `push` en `sync_states`.

## Flujo nube hacia local

1. El worker consulta `GET /api/sync/events/pull`.
2. Guarda cada evento recibido en `sync_inbox` local.
3. Evita duplicados por `event_uuid`.
4. Confirma a la nube con `POST /api/sync/events/{event_uuid}/ack`.
5. Registra estado `pull` en `sync_states`.

## Reglas importantes

- El worker siempre trabaja dentro de una empresa especifica.
- No comparte eventos entre empresas.
- El `event_uuid` mantiene idempotencia.
- Los eventos bajados desde la nube primero quedan en `sync_inbox`.
- Si el evento es soportado, se aplica y queda como `applied`.
- Si el evento no es soportado, queda como `ignored`.
- Si falta informacion o una relacion local no existe, queda como `failed` con mensaje en espanol.

## Eventos aplicados en esta fase

- `product.created`
- `product.updated`
- `price_list.created`
- `price_list.updated`
- `product_price.created`
- `product_price.updated`
- `price.updated`
- `exchange_rate_type.created`
- `exchange_rate_type.updated`
- `exchange_rate.created`
- `exchange_rate.updated`
- `payment_method.created`
- `payment_method.updated`

## Eventos que aun quedan para fases siguientes

- movimientos de stock entre sedes;
- ventas completas desde local hacia nube con conciliacion avanzada;
- clientes y cuentas por cobrar;
- garantias;
- permisos y usuarios.

## Pruebas

Se agrego:

- `tests/Feature/Sync/SyncWorkerCommandTest.php`

Casos cubiertos:

- registra nodo, sube eventos locales, baja eventos de nube y confirma `ack`;
- aplica eventos de precios por lista sin mezclar empresas;
- marca eventos invalidos como `failed` con mensaje en espanol;
- no ejecuta el worker si la empresa no existe;
- valida que no se hagan llamadas HTTP si el tenant no existe.
