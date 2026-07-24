# Plan de Notificaciones Push en Vivo (WebSocket / Laravel Reverb)

Fecha: 2026-07-23

## Contexto

El sistema de traslados inter-empresa (InventoryTransferRequests) tenia
dos problemas:

1. **Bug de UI**: el badge rojo del sidebar con el contador de solicitudes
   pendientes NO se quitaba al aceptar o rechazar una solicitud. Las
   mutaciones `useAcceptTransferRequest`, `useRejectTransferRequest`,
   `useCancelTransferRequest` invalidaban solo `lists` y `detail`, pero
   NO invalidaban la queryKey `unread-count` del badge. (Esto ya se
   arreglo el 2026-07-23.)

2. **Notificacion con delay**: las solicitudes nuevas se enteraban al
   usuario solo cuando este visitaba la pagina `/inventory-transfer-requests`
   o cuando el polling de 30s del badge disparaba un re-fetch. Eso da
   hasta 30 segundos de delay entre la creacion de la solicitud y la
   primera pista visual (el badge incrementa).

3. **Sin push in-app**: cuando llegaba una solicitud, no habia campanada
   ni toast. Solo el badge del sidebar cambiaba su contador. Se agrego
   un toast reactivo (compara valor anterior del contador y dispara
   `toast.error` si SUBE) en `frontend/src/components/layout/Sidebar.tsx`
   via `useTransferRequestArrivalNotification`. Pero sigue dependiendo
   del polling cada 30s.

## Objetivo

Reemplazar el polling por **push real via WebSocket** con Laravel Reverb:

- Latencia de notificacion: < 1 segundo.
- Funciona aunque el usuario este en otra pestana del POS.
- Funciona con el navegador minimizado (conexion persistente).
- Compatible con la solucion actual: si Reverb no esta disponible,
  cae al polling de 30s como fallback.

## Que se hara

### Fase 1: Backend (Reverb)

1. **Instalar Reverb** via composer: `composer require laravel/reverb`.
2. **Publicar config**: `php artisan install:broadcasting` genera
   `config/broadcasting.php` con `reverb` como driver por default.
3. **Eventos**: en `app/Modules/InventoryTransferRequests/Events/`,
   crear `TransferRequestCreated` que implementa `ShouldBroadcast`.
   Define `broadcastOn(): Channel` que retorna un canal privado por
   tenant destino (`private-tenant.{destination_id}`) o por usuario
   (`private-user.{user_id}`) si queremos notificar a admins especificos.
4. **Disparador**: en `InventoryTransferRequestController::store`
   (o `InventoryTransferRequestService::createRequest`), despues de la
   creacion, disparar `event(new TransferRequestCreated($request))`.
5. **Auth de canales**: implementar un canal policy (`app/Modules/
   InventoryTransferRequests/Broadcasting/TransferRequestChannel.php`)
   que verifica que el user pertenezca al tenant destino antes de
   permitir la suscripcion.
6. **Variable de entorno**: agregar `BROADCAST_CONNECTION=reverb` al `.env`.

### Fase 2: Servidor Reverb

En el VPS se necesita un proceso PHP corriendo Reverb en background.
Dos opciones:

**Opcion A (recomendada para este VPS multi-tenant)**: contenedor Docker
adicional dedicado a Reverb.

- Pros: aislado, escalable, mismo stack que el resto.
- Contras: configurar proxy/host con TLS, requiere Dockerfile.

**Opcion B (mas simple para empezar)**: proceso systemd nativo en el
VPS, separado del backend Laravel.

- Pros: cero infra nueva.
- Contras: si Reverb crashea, no hay reinicio automatico.

**Comando recomendado**: `php artisan reverb:start --port=8081 --hostname=0.0.0.0`.

Service file sugerido (`/etc/systemd/system/inventarioarens-reverb.service`):

```ini
[Unit]
Description=InventarioArens Reverb WebSocket Server
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/opt/inventarioarens-cloud
ExecStart=/usr/bin/php artisan reverb:start --port=8081 --hostname=0.0.0.0
Restart=always
RestartSec=5
StandardOutput=append:/var/log/inventarioarens/reverb.log
StandardError=append:/var/log/inventarioarens/reverb-error.log

[Install]
WantedBy=multi-user.target
```

### Fase 3: Frontend (Laravel Echo)

1. **Instalar dependencias**: `cd frontend && pnpm add laravel-echo pusher-js`.
2. **Configurar Echo**: en `frontend/src/lib/echo.ts` (nuevo archivo):
   ```ts
   import Echo from 'laravel-echo';
   import Pusher from 'pusher-js';

   declare global {
     interface Window {
       Echo?: Echo<'reverb'>;
       Pusher?: typeof Pusher;
     }
   }

   if (typeof window !== 'undefined' && !window.Echo) {
     window.Pusher = Pusher;
     window.Echo = new Echo({
       broadcaster: 'reverb',
       key: import.meta.env.VITE_REVERB_APP_KEY,
       wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
       wsPort: import.meta.env.VITE_REVERB_PORT ?? 8081,
       wssPort: import.meta.env.VITE_REVERB_PORT ?? 8081,
       forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
       enabledTransports: ['ws', 'wss'],
     });
   }
   ```
3. **Inicializar**: importar `'@/lib/echo'` en `frontend/src/main.tsx`.
4. **Hook React**: en `frontend/src/features/inventory-transfer-requests/`
   crear `useTransferRequestBroadcast(currentTenantId)` que se suscribe
   a `private-tenant.{tenantId}` y dispara el mismo toast que el hook
   de polling cuando llega el evento.
5. **Variable de entorno frontend**: en `frontend/.env`:
   ```
   VITE_REVERB_APP_KEY=inventarioarens-key
   VITE_REVERB_HOST=localhost
   VITE_REVERB_PORT=8081
   VITE_REVERB_SCHEME=http
   ```
   En produccion: `VITE_REVERB_HOST=app.miinventariofacil.com`
   y `VITE_REVERB_SCHEME=https`.

### Fase 4: Configuracion en Traefik (VPS)

Como el VPS usa Traefik como reverse proxy (ver `docs/MIGRACION_VPS_2026-07-21.md`),
hay que agregar una nueva ruta para el WebSocket. Traefik ya soporta
WebSocket nativamente (HTTP Upgrade), pero requiere configuracion
especifica en la route.

Agregar a `/root/deploy/core/traefik-config/inventarioarens.yml` (o el
archivo equivalente):

```yaml
http:
  routers:
    inventarioarens-ws:
      rule: "Host(`app.miinventariofacil.com`) && PathPrefix(`/ws/`)"
      service: inventarioarens-ws
      tls: {}
  services:
    inventarioarens-ws:
      loadBalancer:
        servers:
          - url: "http://172.18.0.1:8081"
```

Y en `frontend/vite.config.ts` agregar proxy para `/ws/` en dev:

```ts
proxy: {
  '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
  '/ws': {
    target: 'ws://127.0.0.1:8081',
    ws: true,
    changeOrigin: true,
  },
},
```

## Consideraciones para el VPS

### Recursos

- Reverb consume poca RAM (~30-50 MB) y CPU baja cuando no hay conexiones.
- Para una empresa con 10 usuarios concurrentes, no llega a 1% CPU.
- Ancho de banda: cada conexion WebSocket usa ~1 KB/s idle, mas payloads
  en picos. Estimado: < 1 MB/s para 50 conexiones.

### Seguridad

- Reverb soporta autenticacion de canales privados via `Broadcasting/
  TransferRequestChannel.php` con `join`. Verificar que el user este
  autenticado y pertenezca al tenant destino antes de permitirle la
  suscripcion.
- TLS obligatorio en produccion: el cliente se conecta por `wss://`
  (Traefik hace TLS termination).
- Rate limiting: Reverb tiene `max_connections` y `max_message_size` en
  `config/reverb.php`. Configurar conservadoramente.

### Monitoreo

- Logs: stdout/stderr del proceso Reverb, capturarlos a
  `/var/log/inventarioarens/reverb.log` (ya configurado en el service
  file propuesto).
- Health check: agregar ruta `GET /reverb/health` que retorne 200 si el
  proceso esta vivo (no nativo de Reverb; usar script PHP simple).
- Alertas: agregar a `monitoring/healthchecks.json` revision del puerto
  8081 cada 60s.

### Persistencia

- Reverb es in-memory por default (no persiste nada). Los eventos
  broadcast son fire-and-forget; si el cliente esta offline, los pierde.
- Para historial de notificaciones, ya existe la tabla
  `inventory_transfer_requests` con todos los campos. El badge cuenta
  los pendientes via query.

### Escalabilidad

- Reverb single-node soporta hasta ~10K conexiones concurrentes.
- Para mas, migrar a Pusher (SaaS) o Redis-based broadcasting (Soketi).
- Documentado en `config/reverb.php` bajo `options.servers`.

### Rollback plan

Si Reverb falla en produccion, el sistema cae al polling de 30s
automáticamente porque el hook `useTransferRequestArrivalNotification`
sigue funcionando con el contador de TanStack Query. La UI sigue
operativa, solo con delay.

Para revertir Reverb:

1. Detener servicio: `systemctl stop inventarioarens-reverb`.
2. Remover variable `BROADCAST_CONNECTION=reverb` del `.env`.
3. Frontend sigue funcionando con polling. No requiere cambios.

## Plan de testing

1. **Unit tests backend**: `tests/Feature/InventoryTransferRequests/
   TransferRequestBroadcastTest.php`:
   - Crear solicitud dispara evento `TransferRequestCreated`.
   - Canal `private-tenant.{destination_id}` se suscribe solo a usuarios
     del tenant destino.
   - Evento NO se difunde si destination es el mismo tenant que origin
     (caso borde).

2. **Unit tests frontend**: `frontend/src/features/inventory-transfer-
   requests/__tests__/useTransferRequestBroadcast.test.tsx`:
   - Hook se suscribe a canal correcto.
   - Hook NO se suscribe si no hay tenant.
   - Al recibir evento, llama `toast.error` con mensaje correcto.

3. **E2E manual**:
   - Login en danubio y danubio-soledad en dos navegadores distintos.
   - Crear solicitud desde danubio.
   - En danubio-soledad, ver toast < 1 segundo.
    - Aceptar solicitud en danubio-soledad.
    - En danubio, ver evento `TransferRequestAccepted`.

## Arranque de Reverb en local (desarrollo)

Reverb NO se autoinstala ni se arranca como parte de `php artisan serve`
o de `pnpm dev`. Hay que arrancarlo manualmente en una terminal
separada. Sin esto, el push WebSocket no viaja y las notificaciones
del backend tardan hasta 30s (lo que tarda el polling en re-fetchar).

```bash
# Terminal 1: backend Laravel
php artisan serve --host=127.0.0.1 --port=8000

# Terminal 2: Reverb (WebSocket server)
php artisan reverb:start --host=0.0.0.0 --port=8081

# Terminal 3: Vite dev server (frontend)
cd frontend && pnpm dev
```

En background (no se cierra al cerrar la terminal):

```bash
nohup php artisan reverb:start --host=0.0.0.0 --port=8081 > /tmp/reverb.log 2>&1 &
```

Verificar que esta corriendo:

```bash
ps aux | grep reverb
curl -sI --max-time 3 http://127.0.0.1:8081/
# Debe responder 404 (Reverb no expone HTTP, solo WebSocket en /app/{key})
# Si da connection refused, Reverb NO esta corriendo.
```

Ver `docs/REVERB_TROUBLESHOOTING.md` para la guia completa de
diagnostico cuando las notificaciones no llegan.

## Checklist para subir al VPS

- [ ] Backend: `composer require laravel/reverb`
- [ ] Backend: `php artisan install:broadcasting`
- [ ] Backend: `.env`: `BROADCAST_CONNECTION=reverb` + `REVERB_APP_ID`,
      `REVERB_APP_KEY`, `REVERB_APP_SECRET`
- [ ] Frontend: `pnpm add laravel-echo pusher-js`
- [ ] Frontend: `.env`: `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`,
      `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME`
- [ ] Servicio systemd `inventarioarens-reverb.service` instalado y
      habilitado (`systemctl enable --now`)
- [ ] Ruta Traefik `/ws/` agregada (NO modificar routes existentes; solo
      anadir un router nuevo)
- [ ] Firewall: abrir puerto 8081 SOLO entre Traefik y el host Docker
      (no exponer a internet; Traefik maneja TLS termination)
- [ ] Monitoreo: agregar health check del puerto 8081
- [ ] Probar manualmente: crear solicitud desde danubio, ver toast
      en danubio-soledad en < 1 segundo
- [ ] Si falla, revertir: `systemctl stop inventarioarens-reverb` + quitar
      `BROADCAST_CONNECTION=reverb` del `.env`. Frontend sigue
      funcionando con polling.

## Riesgos identificados

1. **Conflictos con otros productos en el VPS**: el VPS multi-tenant
   (212.28.176.157) tiene 27 contenedores Docker de OTROS productos.
   Reverb usa puerto 8081. Verificar que ese puerto no este usado por
   otra app. Si lo esta, usar 8082 u otro disponible.

2. **Traefik y WebSocket**: Traefik v2.x y v3.x soportan WebSocket nativamente,
   pero hay que verificar que la configuracion del router use `passthrough`
   o equivalente para headers de upgrade. Si no funciona, usar sticky
   sessions o nginx como proxy intermedio.

3. **CORS y Echo**: el cliente WebSocket se conecta al mismo host del
   frontend (`ws://app.miinventariofacil.com`). Si el frontend esta en
   un CDN, Reverb debe estar expuesto en el mismo dominio o usar CORS.

4. **TamaNo de mensajes**: Reverb limita a 10 KB por mensaje por default.
   Si las notificaciones crecen (ej. con detalle de la solicitud),
   comprimir o paginar.

## Alternativa sin Reverb

Si Reverb resulta complicado en el VPS actual (por restricciones de
Traefik o Docker), la alternativa es:

- **Pusher** (SaaS, plan gratuito limitado): cambiar `BROADCAST_DRIVER=
  pusher` + `PUSHER_APP_KEY`. Pros: zero infra. Contras: 200K mensajes
  gratis/dia, latencia ~100ms.
- **Soketi** (Pusher-compatible, self-hosted): imagen Docker `soketi/soketi`.
  Pros: zero infra nueva si Docker disponible. Contras: misma
  complejidad que Reverb.

Para el alcance de esta Fase 1, Reverb sigue siendo la opcion recomendada
por ser nativa de Laravel 12.

## Cronologia estimada

- Implementacion backend: 2-3 horas.
- Implementacion frontend: 2-3 horas.
- Configuracion VPS (Traefik + systemd): 1 hora.
- Testing + verificacion manual: 1-2 horas.

Total: ~1 dia de trabajo para tener WebSocket push operativo en el VPS.
