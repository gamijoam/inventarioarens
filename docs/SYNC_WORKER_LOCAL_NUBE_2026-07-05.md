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
- Los eventos bajados desde la nube quedan como `received`.
- Todavia no se aplican automaticamente reglas como cambiar precios, crear productos o actualizar configuraciones. Esa sera la siguiente fase por tipo de evento.

## Pruebas

Se agrego:

- `tests/Feature/Sync/SyncWorkerCommandTest.php`

Casos cubiertos:

- registra nodo, sube eventos locales, baja eventos de nube y confirma `ack`;
- no ejecuta el worker si la empresa no existe;
- valida que no se hagan llamadas HTTP si el tenant no existe.

