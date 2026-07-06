# Correccion del worker automatico de sincronizacion

## Contexto

Se detecto que los cambios de productos si se guardaban en la base local y se generaba el evento en `sync_outbox`, pero no siempre subian automaticamente al VPS.

El caso revisado fue el producto `ADP-BT-VAL` de la empresa `demo-valencia`.

## Diagnostico

- El precio local del producto llego a `USD 4000`.
- El evento local `product.updated` quedo inicialmente como `pending`.
- La sincronizacion manual si funcionaba y subia el evento al VPS.
- La sincronizacion automatica iniciaba el worker, pero el proceso se cerraba a los pocos segundos.

El log del worker mostraba:

```text
Too many arguments to "sync:daemon" command, expected arguments "tenant".
```

## Causa

El script `scripts/sync-worker.ps1` iniciaba el proceso en segundo plano pasando los argumentos como arreglo. En Windows, los valores con espacios, por ejemplo `Local Demo Valencia`, se estaban separando en varios argumentos.

Laravel recibia argumentos adicionales y rechazaba el comando `sync:daemon`.

## Correccion

Se ajusto `scripts/sync-worker.ps1` para construir una linea de argumentos citada antes de llamar a `Start-Process`.

Con esto, valores como el nombre del nodo se envian correctamente al proceso del worker.

## Verificacion

Se valido:

- Sincronizacion manual contra el VPS:
  - eventos subidos: `1`
  - eventos bajados: `39`
  - fallos: `0`
- API del VPS devolvio el producto `ADP-BT-VAL` con `base_price = 4000`.
- Worker automatico quedo activo con PID visible.
- El worker ejecuto varios ciclos cada 15 segundos sin caerse.
- `sync_outbox` local quedo sin eventos pendientes para `demo-valencia`.

## Resultado

La sincronizacion local -> nube ya no depende solo del boton manual. El worker automatico puede quedar activo y procesar cambios nuevos en segundo plano.
