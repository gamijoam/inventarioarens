# API nube permanente y prueba por dominio

Fecha: 2026-07-07

> **⚠️ MIGRACIÓN 2026-07-21**: la URL pública (`https://app.miinventariofacil.com/api`) **sigue
> siendo la misma** (Cloudflare apunta al nuevo VPS `212.28.176.157`). Solo cambió el destino
> interno: nginx+Laravel nativo detrás de Traefik en vez de nginx+Laravel directo. La
> confianza en los `app.miinventariofacil.com`-letsencrypt certs (`expires_at` 2026-09-03)
> se mantiene — Traefik ya tenía el cert en `acme.json`. Ver
> `docs/MIGRACION_VPS_2026-07-21.md`.

## Objetivo

Cerrar la base operativa de nube para que las instalaciones locales usen:

```text
https://app.miinventariofacil.com/api```

en vez de depender de una IP con puerto.

## Estado del VPS

Se valido en el VPS `217.216.80.158`:

- `nginx`: activo y habilitado al arranque.
- `php8.4-fpm`: activo y habilitado al arranque.
- `postgresql`: activo y habilitado al arranque.
- Laravel en `/opt/inventarioarens-cloud`.
- `APP_URL=https://app.miinventariofacil.com`.
- HTTPS activo con Let's Encrypt.

Con esto, la API no depende de dejar una consola abierta con `php artisan serve`.

## Servicio anterior por puerto 8010

El servicio `inventarioarens-cloud-api` sigue activo como compatibilidad temporal para instalaciones que aun tengan guardada una URL antigua.

La ruta recomendada ya no es:

```text
http://217.216.80.158:8010/api
```

La ruta recomendada es:

```text
https://app.miinventariofacil.com/api
```

Cuando todas las instalaciones locales esten migradas al dominio, se puede apagar el servicio antiguo del puerto `8010`.

## Validacion realizada

Desde Windows con red real:

```powershell
Test-NetConnection app.miinventariofacil.com -Port 443
```

Resultado:

- `TcpTestSucceeded: True`

Tambien se consulto una ruta protegida de la API:

```text
https://app.miinventariofacil.com/api/sync/status
```

Resultado:

- `401`, esperado cuando no se envia token.

Luego se ejecuto el worker local contra el dominio:

```powershell
powershell -ExecutionPolicy Bypass -File scripts\sync-worker.ps1 run -TenantSlug demo-valencia -CloudUrl https://app.miinventariofacil.com/api
```

Resultado:

- sincronizacion ejecutada;
- 0 fallos;
- el worker pudo comunicarse con la nube usando HTTPS.

## Ajuste de logs del worker

Se corrigio la escritura del log del worker para permitir lectura/escritura compartida.

Antes podia aparecer este aviso cuando el panel estaba leyendo el log:

```text
Aviso: no se pudo escribir en el log porque esta en uso.
```

Ahora el worker escribe usando modo compartido para evitar ese choque.

## Prueba profunda local-nube

Se adapto `scripts/sync-smoke-test.ps1` para aceptar:

```powershell
-CloudApiUrl "https://app.miinventariofacil.com/api"
```

Con ese parametro, la prueba no levanta una API temporal en `127.0.0.1`, sino que usa la API publica del dominio.

Nota importante:

- Esta prueba profunda tambien valida directamente PostgreSQL nube desde la PC.
- Si la red/VPN/firewall bloquea PostgreSQL directo, la prueba puede fallar aunque la sincronizacion por API funcione correctamente.
- Operativamente, el cliente local debe sincronizar por API HTTPS, no conectarse directo a PostgreSQL nube.

## Siguiente paso recomendado

1. Migrar cualquier instalacion local que aun use IP/puerto a `https://app.miinventariofacil.com/api`.
2. Verificar que el worker automatico sincronice por dominio.
3. Cuando no queden clientes usando `:8010`, apagar el servicio antiguo:

```bash
systemctl disable --now inventarioarens-cloud-api
```

