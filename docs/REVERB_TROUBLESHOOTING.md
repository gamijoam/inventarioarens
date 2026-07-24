# Troubleshooting de Notificaciones WebSocket y Polling

Fecha: 2026-07-23

## Caso real del 2026-07-23

**Sintoma reportado:** el usuario abria dos navegadores (danubio y danubio-soledad). Las solicitudes creadas llegaban como push en < 1s, pero al aceptar o rechazar desde el destino, el origin no veia NINGUN tipo de notificacion (ni toast, ni badge incrementando). Esperaba ~10s, ~20s, y nada. Veia el cambio solo al refrescar manualmente la pagina.

**Diagnostico que resulto correcto:** el backend SI disparaba el evento `TransferRequestAccepted` (verificado con `event(new TransferRequestAccepted($accepted))` en el service). El frontend SI escuchaba el evento. Pero Reverb NO estaba corriendo en local, asi que el push nunca viajaba por WebSocket. El polling de 30s si funcionaba, por eso veia el cambio eventualmente (al re-fetch). El usuario lo describio como "15-20 segundos" porque los dos refrescos se solapaban.

Lección: **un delay de 10-30s en notificaciones NO es bug del codigo, es polling puro. Para que sea < 1s, Reverb DEBE estar corriendo.**

## Arquitectura: WebSocket + Polling coexisten

El sistema actual tiene DOS mecanismos paralelos, ambos funcionando al mismo tiempo:

1. **WebSocket push (Reverb + Laravel Echo)**: latencia < 1s, requiere que el servidor Reverb este corriendo y que el navegador pueda establecer conexion WS.
2. **Polling reactivo (TanStack Query)**: latencia hasta 30s, siempre funciona aunque Reverb este caido. Usa el hook `useUnreadTransferRequestsCount` que refetchea cada 30s (configurable).

El hook `useTransferRequestBroadcast` escucha los 4 eventos broadcast. Si Echo no esta disponible (Reverb caido), el hook es un no-op. El polling sigue funcionando independientemente.

## Diagnostico paso a paso cuando "no llegan las notificaciones"

### Paso 1: Verificar si Reverb esta corriendo

```bash
# En el host donde corre el backend Laravel
ps aux | grep reverb
# O verificar que el puerto 8081 esta abierto
curl -sI --max-time 3 http://127.0.0.1:8081/
# Debe responder 404 (no expone HTTP, solo WebSocket en /app/{key})
# Si da connection refused, Reverb NO esta corriendo.
```

Si NO esta corriendo, arrancarlo:

```bash
cd /home/gamijoam/Documentos/INVENTARIOARENS
php artisan reverb:start --host=0.0.0.0 --port=8081
```

Esto arranca Reverb como proceso foreground. Para correrlo en background:

```bash
nohup php artisan reverb:start --host=0.0.0.0 --port=8081 > /tmp/reverb.log 2>&1 &
```

Para produccion (VPS) usar systemd: `inventarioarens-reverb.service` (ver `REVERB_WEBSOCKETS_PLAN.md`).

### Paso 2: Verificar el broadcast en el backend

Los eventos broadcast se disparan desde `InventoryTransferRequestService::accept/reject/cancel/create`. Verificar en logs:

```bash
# En el log de Reverb deberian aparecer los publish
tail -f /tmp/reverb.log
# O agregar un listener en tinker para debug:
php artisan tinker
> Event::listen(\App\Modules\InventoryTransferRequests\Events\TransferRequestAccepted::class, fn($e) => logger()->info('event accepted', $e->broadcastWith()));
```

### Paso 3: Verificar la conexion WebSocket en el navegador

Abrir DevTools (F12) en el navegador donde se esperan las notificaciones. Ir a:

- **Network** → filtrar por "WS" o "WebSocket". Debe haber una conexion a `ws://localhost:8081/app/inventarioarens-key` (o el host del VPS en produccion).
- **Console** → filtrar por `[ITR]`. Cuando llegue un evento broadcast, debe aparecer el log:
  ```
  [ITR] WebSocket event received: inventory-transfer-requests.accepted
  { event: {...}, currentTenantId: 5 }
  ```

### Paso 4: Interpretar los resultados

| Console log | Network WS | Resultado probable |
|---|---|---|
| Log aparece | Conexion WS abierta | Push funciona, el problema es de UI/render |
| Log aparece | NO hay conexion WS | El navegador se conecto antes al backend que levanto Reverb; recarga |
| NO log | Conexion WS abierta | El evento no se esta disparando en backend, o el canal policy filtra al usuario |
| NO log | NO hay conexion WS | Reverb no esta corriendo, o el puerto/proxy no deja pasar WS |

## Errores comunes y soluciones

### 1. "Las notificaciones no llegan" (15-30s de delay)

**Causa mas comun:** Reverb no esta corriendo. Verificar con `ps aux | grep reverb` o `curl -sI http://127.0.0.1:8081/`. Si no responde, arrancarlo.

**Solucion:** `php artisan reverb:start --host=0.0.0.0 --port=8081`. El polling sigue funcionando mientras tanto, solo con delay de 30s.

### 2. "Las notificaciones de accept/reject no llegan al origin"

**Causa:** el hook del frontend `useTransferRequestBroadcast` escucha los 4 eventos, pero el navegador destino esta en otra pestana o incognito. Echo SÍ reenvia el evento al navegador correcto, pero CADA pestana tiene su propia conexion WS. Si el origin esta en una pestana que NO ha establecido la conexion aun (o que se cerro), no recibira el push.

**Solucion:** verificar en la consola del navegador del origin: `[ITR] WebSocket event received`. Si aparece, el push llego; el problema es que el toast no se renderiza por algun error de UI. Si NO aparece, es cache del navegador o que el navegador del origin esta en background y Echo no se ha conectado.

### 3. "El toast no aparece aunque veo el log en consola"

**Causa:** el handler de Toast puede estar fallando por un error de tipos o porque `currentTenantId` no matchea con el tenant destino del evento.

**Diagnostico:**
```js
[ITR] WebSocket event received: inventory-transfer-requests.accepted
{ event: { id: 7, origin_tenant_id: 2, destination_tenant_id: 3, ... }, currentTenantId: 3 }
```

El handler verifica que `event.origin_tenant_id === currentTenantId` (3 === 3) para accepted. Si no matchea, no muestra el toast. Verificar que el `currentTenantId` del frontend corresponde al tenant que se espera.

### 4. "El badge no se limpia al entrar a la pagina"

**Causa:** el `useUnreadTransferRequestsCount` cuenta items cuyo `requested_at > lastSeenAt`. El `lastSeenAt` se persiste en `localStorage` con clave `itr.lastSeenAt.tenant.{id}`. Si abriste el navegador en incognito, no hay `lastSeenAt` previo, asi que el badge muestra el total real (no decrementa al entrar).

**Solucion:** entrar dos veces a la pagina. La primera vez se setea el `lastSeenAt`. La segunda vez, los items que llegaron despues de la primera vez seran los unicos que cuenten.

### 5. "Echo dice 'connection refused' en consola"

**Causa:** el navegador no puede alcanzar el servidor de Reverb. En local, eso es porque Reverb no esta corriendo o el proxy de Vite (puerto 5173) no redirige a Reverb (puerto 8081). En produccion (VPS), el proxy de Traefik para `/ws/` no esta configurado.

**Solucion local:** arrancar Reverb y refrescar la pagina. Si Vite no redirige `/ws/`, agregar al `vite.config.ts`:
```ts
proxy: {
  '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
  '/ws': { target: 'ws://127.0.0.1:8081', ws: true, changeOrigin: true },
}
```

**Solucion produccion (VPS):** ver `REVERB_WEBSOCKETS_PLAN.md` seccion "Checklist para subir al VPS".

### 6. "El backend lanza BroadcastingDriver error"

**Causa:** `BROADCAST_CONNECTION=log` en lugar de `reverb` en produccion. En desarrollo esta bien (los eventos se logean, no se envian por WS). En produccion DEBE ser `reverb`.

**Solucion:** `.env` debe tener `BROADCAST_CONNECTION=reverb` y las vars Reverb (`REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME`) configuradas.

## Comandos utiles para debugging

```bash
# Ver si Reverb esta corriendo
ps aux | grep reverb

# Arrancar Reverb en background
nohup php artisan reverb:start --host=0.0.0.0 --port=8081 > /tmp/reverb.log 2>&1 &

# Ver los logs de Reverb
tail -f /tmp/reverb.log

# Verificar que el puerto esta abierto
ss -tlnp | grep 8081
# o
lsof -i :8081

# Disparar un evento de prueba y ver si se loguea
php artisan tinker
> event(new \App\Modules\InventoryTransferRequests\Events\TransferRequestAccepted($someTransferRequest));
# Debe aparecer en /tmp/reverb.log

# Limpiar cache si la config cambio
php artisan config:clear
php artisan cache:clear
```

## Diferencias clave entre local y produccion (VPS)

| Aspecto | Local (desarrollo) | Produccion (VPS) |
|---|---|---|
| `BROADCAST_CONNECTION` | `log` (default en `.env.example`) | `reverb` |
| Reverb proceso | Manual (foreground o `nohup`) | systemd service `inventarioarens-reverb.service` |
| Puerto | 8081 local | 8081 interno (Traefik hace TLS termination en `/ws/`) |
| CORS / Origin | No (mismo host) | Traefik + config Reverb con `allowed_origins` |
| Latencia esperada | < 1s (si Reverb corre) | < 1s (con Reverb + Traefik) |
| Fallback polling | Cada 30s via TanStack Query | Cada 30s (mismo codigo) |

## Lecciones aprendidas

1. **Reverb NO se autoinstala.** Hay que arrancarlo manualmente. Esto es por diseno: no debe correr como parte del request lifecycle del backend.

2. **El delay de notificaciones NO es bug del codigo en local.** El codigo emite el evento, el frontend escucha, pero sin servidor Reverb el push no viaja. En produccion con Reverb activo, el delay es < 1s.

3. **El polling es el fallback robusto.** Cada 30s el frontend re-fetchea el contador. Si Reverb falla, el polling sigue mostrando updates. Es la red de seguridad.

4. **El log `[ITR]` en consola es la mejor diagnostico.** Si el usuario reporta "no llega", pedirle que abra DevTools Console y verifique si ve el log. Si no ve, es cache o Reverb. Si ve pero no hay toast, es un bug de UI.

5. **Cada tab del navegador tiene su propia conexion WS.** Si el usuario tiene 3 tabs, son 3 conexiones Reverb independientes. Reverb maneja 10K conexiones concurrentes sin problema.
