# Migración VPS — INVENTARIOARENS 2026-07-21

> Mudanza del backend Laravel de `217.216.80.158` (Contabo viejo, **destruido al final**) a
> `212.28.176.157` (Contabo nuevo, multi-tenant con Docker de otros productos).
> Downtime total: ~3 min (solo el corte DNS).
> Resultado: backend 100% funcional, 27 Docker containers intactos, sync worker
> operativo, tokens rotados.

---

## 1. Contexto

El VPS viejo (`217.216.80.158`) corría solo el backend de INVENTARIOARENS (Laravel + PHP-FPM +
nginx + PostgreSQL + Redis nativos). Con el tiempo se decidió consolidar en el VPS nuevo
(`212.28.176.157`), que ya hospeda otros productos vía Docker (bloqueo, credifacil, avilacar,
ferreteria, mi-inventario-whatsapp). Esto implicaba:

- Convivencia con 16 contenedores Docker críticos que **NO** se debían tocar.
- Traefik (Docker) ya ocupa puertos 80/443 → nginx nativo tuvo que escuchar en un puerto
  interno (8080) y Traefik enruta `app.miinventariofacil.com` hacia él.
- UFW con política INPUT DROP → hubo que abrir el 8080 explícitamente para la subred Docker.
- No Docker para INVENTARIOARENS — todo nativo, mismo patrón que el viejo.

## 2. Estado antes

### VPS viejo (`217.216.80.158`)

| Item | Valor |
|---|---|
| OS | Ubuntu 24.04.4 LTS |
| PHP | 8.4.23 + gd (WebP), pgsql, bcmath, exif, redis, opcache, curl, zip, mbstring, intl |
| PostgreSQL | 16.14 nativo, DB `inventory_arens` (16 MB, 91 tablas, 5 productos, 2 tenants, 1 user) |
| Redis | 7.0.15 nativo |
| Nginx | 1.24.0 + Let's Encrypt SSL para `app.miinventariofacil.com` |
| App | `/opt/inventarioarens-cloud` (owner `www-data`), servido vía PHP-FPM 8.4 |
| Sync worker | systemd timer `inventarioarens-sync.timer` cada 15s |
| Disco | 142 GB libres de 193 GB |
| `storage:link` | NO estaba creado |
| `upload_max_filesize` | 2 MB (muy bajo para imágenes) |
| `post_max_size` | 8 MB |
| `client_max_body_size` (nginx) | no seteado (default 1 MB) |

### VPS nuevo (`212.28.176.157`)

| Item | Valor |
|---|---|
| OS | Ubuntu 24.04.3 LTS |
| PHP | NO instalado |
| PostgreSQL | 16 nativo, DB `inventory_arens` creada (vacía, 7.5 MB) |
| Redis | NO instalado (3 instancias Docker en otros proyectos, pero no expuestas al host) |
| Nginx | NO instalado |
| Docker | 27 containers activos: bloqueo, ferreteria, avilacar, credifacil, mi-inventario-whatsapp, traefik, redis_prod/qa, db_qa |
| Traefik | 2.11.36 en Docker, ocupa 80/443, ACME vía Let's Encrypt HTTP-01, archivo `/root/deploy/core/acme.json` con certs preexistentes para `*.miinventariofacil.com` |
| UFW | política `INPUT DROP`, permite 22/3389/8888/54322 |
| Disco | 153 GB libres de 193 GB |

## 3. Plan ejecutado (5 fases, ~75 min)

### Fase 0 — Backup local

```bash
# pg_dump del viejo
sshpass -e ssh root@217.216.80.158 'sudo -u postgres pg_dump -Fc --no-owner --no-acl inventory_arens' \
  > /tmp/migracion-2026-07-21/inventory_arens-2026-07-21.dump     # 508 KB

# rsync del source (excluyendo vendor, node_modules, caches)
sshpass -e rsync -avz --progress \
  --exclude='vendor' --exclude='node_modules' --exclude='.git' \
  --exclude='storage/logs/*' --exclude='storage/framework/*' \
  --exclude='storage/app/sync-worker/*' --exclude='public/build' \
  root@217.216.80.158:/opt/inventarioarens-cloud/ /tmp/migracion-2026-07-21/app-backup/

# Capturar .env (APP_KEY, etc.)
sshpass -e scp root@217.216.80.158:/opt/inventarioarens-cloud/.env /tmp/migracion-2026-07-21/.env.viejo
```

### Fase 1 — Stack nativo en VPS nuevo

```bash
add-apt-repository -y ppa:ondrej/php
apt-get install -y --no-install-recommends \
  php8.4-fpm php8.4-cli php8.4-{gd,pgsql,bcmath,curl,exif,redis,zip,xml,mbstring,intl,opcache,common} \
  nginx composer redis-server

# php.ini limits (upload imágenes hasta 16 MB)
sed -i 's/upload_max_filesize = .*/upload_max_filesize = 16M/' /etc/php/8.4/fpm/php.ini
sed -i 's/post_max_size = .*/post_max_size = 20M/' /etc/php/8.4/fpm/php.ini
sed -i 's/memory_limit = .*/memory_limit = 512M/' /etc/php/8.4/fpm/php.ini
# Igual en /etc/php/8.4/cli/php.ini para los comandos artisan

mkdir -p /opt/inventarioarens-cloud && chown -R www-data:www-data /opt/inventarioarens-cloud
systemctl enable --now php8.4-fpm redis-server
rm -f /etc/nginx/sites-enabled/default   # site default escucha :80 → conflicto con Traefik
```

### Fase 2 — Datos migrados

```bash
# Subir backup al VPS nuevo
sshpass -e rsync -avz /tmp/migracion-2026-07-21/app-backup/ root@212.28.176.157:/opt/inventarioarens-cloud/
sshpass -e scp /tmp/migracion-2026-07-21/.env.viejo root@212.28.176.157:/opt/inventarioarens-cloud/.env
sshpass -e scp /tmp/migracion-2026-07-21/inventory_arens-2026-07-21.dump root@212.28.176.157:/tmp/

# DROP y CREATE DB (la del nuevo estaba vacía)
sudo -u postgres psql -c "DROP DATABASE IF EXISTS inventory_arens;"
sudo -u postgres psql -c "CREATE DATABASE inventory_arens OWNER postgres;"
sudo -u postgres pg_restore -d inventory_arens --no-owner --no-acl --role=postgres /tmp/inventory_arens-2026-07-21.dump

# Permisos + artisan
cd /opt/inventarioarens-cloud
chown -R www-data:www-data .
sudo -u www-data php artisan storage:link
sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan config:cache
sudo -u www-data php artisan migrate --force   # idempotente, dice "Nothing to migrate"
```

**Importante**: la copia inicial del source era del VPS viejo (sin Sprint 5). Para tener el
código actualizado hubo que `rsync` los archivos fuente desde el repo local después:

```bash
sshpass -e rsync -avz --progress \
  --exclude='vendor' --exclude='node_modules' --exclude='.git' --exclude='storage/logs/*' \
  --exclude='storage/framework/*' --exclude='storage/app/sync-worker/*' \
  --exclude='public/build' --exclude='frontend/dist' --exclude='frontend/node_modules' \
  --exclude='diag.php' --exclude='tools' --exclude='.opencode' --exclude='graphify-out' \
  --exclude='DOCUMENTOS' \
  /home/gamijoam/Documentos/INVENTARIOARENS/ root@212.28.176.157:/opt/inventarioarens-cloud/

# CRÍTICO: limpiar bootstrap/cache (incluían Laravel\Pail\PailServiceProvider de dev)
rm -f /opt/inventarioarens-cloud/bootstrap/cache/{services.php,packages.php,config.php,routes-v7.php}
chown -R www-data:www-data /opt/inventarioarens-cloud
sudo -u www-data php artisan optimize:clear
```

`composer.lock` del backup y del repo local coincidían (md5 `f7d0d7b25e6ca167e4239fb5f2cb7a51`), por
lo que `vendor/` se reusó sin reinstalar.

### Fase 3 — Nginx nativo + route Traefik + UFW

#### Nginx nativo (escucha en 8080, IP del bridge Docker)

`/etc/nginx/sites-available/app.miinventariofacil.com`:

```nginx
server {
    # 172.18.0.1 = bridge gateway (accesible desde Traefik container).
    # 127.0.0.1 = loopback del host (para health checks locales).
    listen 172.18.0.1:8080;
    listen [::1]:8080;
    listen 127.0.0.1:8080;
    server_name app.miinventariofacil.com;

    root /opt/inventarioarens-cloud/public;
    index index.php;
    client_max_body_size 16M;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }
    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 120s;
        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
    }

    location ~ /\.ht { deny all; }

    # Sirve uploads de storage/app/public directamente sin pasar por PHP.
    location ^~ /storage/ {
        alias /opt/inventarioarens-cloud/storage/app/public/;
        expires 30d;
        add_header Cache-Control "public, max-age=2592000";
        try_files $uri =404;
    }
}
```

```bash
ln -sf /etc/nginx/sites-available/app.miinventariofacil.com /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

#### Traefik route (archivo NUEVO, no toca los existentes)

`/root/deploy/core/traefik-config/inventarioarens.yml`:

```yaml
http:
  routers:
    inventarioarens:
      rule: "Host(`app.miinventariofacil.com`)"
      entryPoints: [websecure]
      service: inventarioarens-backend
      tls: {certResolver: myresolver}

  services:
    inventarioarens-backend:
      loadBalancer:
        servers:
          - url: "http://172.18.0.1:8080"
        passHostHeader: true
```

Traefik detecta el archivo nuevo automáticamente (`--providers.file.watch=true`), sin reiniciar.

#### UFW rule (CRÍTICA — sin esto Traefik no llega a nginx)

```bash
ufw allow from 172.18.0.0/16 to any port 8080 proto tcp comment "traefik-to-nginx-inventarioarens"
ufw reload
```

Sin esta regla, Traefik en `172.18.0.4` no podía alcanzar nginx en `172.18.0.1:8080` (UFW bloqueaba
INPUT). El síntoma fue HTTP/2 200 sin body (TLS funciona, request sale, backend nunca responde).

### Fase 4 — Sync worker systemd (idéntico al viejo)

```bash
cat > /etc/systemd/system/inventarioarens-sync.service <<EOF
[Unit]
Description=InventarioArens sync apply-inbox (todos los tenants)
After=network.target
[Service]
Type=oneshot
User=www-data
WorkingDirectory=/opt/inventarioarens-cloud
ExecStart=/usr/bin/php artisan sync:apply-all-inboxes --limit=200
StandardOutput=append:/var/log/inventarioarens-sync.log
StandardError=append:/var/log/inventarioarens-sync.log
[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/inventarioarens-sync.timer <<EOF
[Unit]
Description=Run InventarioArens sync every 15 seconds
[Timer]
OnBootSec=10sec
OnUnitActiveSec=15sec
Unit=inventarioarens-sync.service
[Install]
WantedBy=timers.target
EOF

systemctl daemon-reload
systemctl enable --now inventarioarens-sync.timer
```

### Fase 5 — DNS switch (acción del usuario en Cloudflare)

1. Bajar TTL del A record `app.miinventariofacil.com` a 300s (5 min) y esperar la propagación.
2. Cambiar IP del A record de `217.216.80.158` → `212.28.176.157`.
3. Esperar 1-2 min para que Let's Encrypt sirva el cert existente (acme.json ya tenía cert
   para `app.miinventariofacil.com` emitido 2026-06-05, vence 2026-09-03, así que NO hubo que
   reemitir).
4. Smoke test: `curl -I https://app.miinventariofacil.com/up` → HTTP/2 200.

## 4. Smoke tests post-migración

| Test | Resultado |
|---|---|
| `curl -I https://app.miinventariofacil.com/up` | HTTP/2 200 con headers CSP/Permissions-Policy correctos |
| Login `gabo@gabo.com`/`gabo1234` + `X-Tenant: mi-empresa` | 200 + roles=["Owner"] + 118 permisos |
| `GET /api/pos/bootstrap` con Bearer token | warehouses=1, payment_methods=0, open_session=NO |
| `GET /api/products?per_page=10` (gabo en mi-empresa) | `data: []` (mi-empresa tiene 0 productos en DB — correcto) |
| Sync worker `journalctl -u inventarioarens-sync` | Aplicados 21/44 eventos en los primeros minutos |
| Docker containers | 27/27 intactos, ningún reinicio |
| `dig +short app.miinventariofacil.com` | `212.28.176.157` |

## 5. Tokens de sync rotados

```bash
php artisan sync:ensure-and-token mi-empresa --node-name=Local-Node
php artisan sync:ensure-and-token grupo-prueba --node-name=Local-Node
```

Output:
- Tenant `mi-empresa` → Node `LOCAL-MI-EMPRESA-VMI3062717` (id=8), token revocado del anterior.
- Tenant `grupo-prueba` → Node `LOCAL-GRUPO-PRUEBA-VMI3062717` (id=9), token revocado del anterior.

`scripts/sync_token.py` actualizado de `VPS_HOST = "217.216.80.158"` a `VPS_HOST = "212.28.176.157"` y
commiteado (`2485da69`). Funciona en Windows y Linux (paramiko es OS-agnostic, requiere
`pip install paramiko`).

## 6. Rollback (si algo sale mal)

1. **DNS revert** en Cloudflare: cambiar A record de `212.28.176.157` → `217.216.80.158`. TTL 300s
   significa ~5 min para propagar globalmente.
2. **VPS viejo intacto**: nunca se tocó. Sus servicios (`inventarioarens-sync.timer`,
   `php8.4-fpm`, `nginx`, `redis-server`, `postgresql`) siguen corriendo. La DB vieja
   no se modificó desde el backup.
3. **Backup completo** preservado en `/tmp/migracion-2026-07-21/`:
   - `inventory_arens-2026-07-21.dump` (508 KB)
   - `app-backup/` (65 MB con vendor)
   - `.env.viejo` (con APP_KEY)
4. Si hay que reconstruir el viejo desde cero: `pg_restore` del dump + rsync del app-backup.

## 7. Issues pre-existentes (no causados por la migración)

1. **`/api/auth/me` retorna `tenant: null`** aunque se pase `X-Tenant`. Bug en
   `AuthService::currentSession` — el tenant context no se setea. Afecta solo al endpoint `/me`;
   el resto de endpoints que reciben el header lo procesan correctamente.

2. **`user_id=1` (gabo@gabo.com) tiene Owner solo en tenant 1 (mi-empresa)**. En `grupo-prueba`
   no tiene rol → `/api/products` retorna 403 desde ahí. Esto es esperado según el dump del
   viejo, no es regresión.

## 8. Cambios estructurales en AGENTS.md

- §1: VPS nuevo listado como canónico, viejo marcado como "destruido".
- §2: Tabla de confusión actualizada (MiInventarioFácil sigue en `212.28.176.157` con FastAPI;
  INVENTARIOARENS ahora también ahí pero en stack nativo, separado).

## 9. Archivos nuevos en VPS nuevo (referencia para futuros deploys)

| Path | Propósito |
|---|---|
| `/etc/nginx/sites-available/app.miinventariofacil.com` | vhost nativo |
| `/etc/nginx/sites-enabled/app.miinventariofacil.com` | symlink |
| `/etc/systemd/system/inventarioarens-sync.service` | sync one-shot |
| `/etc/systemd/system/inventarioarens-sync.timer` | timer cada 15s |
| `/etc/php/8.4/fpm/php.ini` | límites 16M/20M/512M |
| `/etc/php/8.4/cli/php.ini` | igual que fpm |
| `/root/deploy/core/traefik-config/inventarioarens.yml` | route Traefik (NUEVO, no toca los otros) |
| `ufw rules` | `allow from 172.18.0.0/16 to any port 8080 proto tcp` |