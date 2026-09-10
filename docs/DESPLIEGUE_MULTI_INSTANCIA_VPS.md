# Despliegue Multi-Instancia en VPS (`/opt/*-cloud`)

> Documento de referencia operativa para las múltiples instancias de **INVENTARIOARENS** desplegadas en el VPS de producción (`212.28.176.157`).
> Última actualización: 2026-09-10.

---

## 1. Visión General

El servidor VPS Contabo (`212.28.176.157`) aloja 4 instalaciones independientes del backend/frontend de **INVENTARIOARENS**, cada una con su propio dominio público, su propio pool de PHP-FPM, su propio puerto Nginx y su propia base de datos PostgreSQL nativa.

Todas las instalaciones comparten el **mismo código base** del repositorio GitHub (`https://github.com/gamijoam/inventarioarens.git`).

| Instancia | Dominio Público | Puerto Nginx | Socket PHP-FPM 8.4 | Base de Datos PostgreSQL | Usuario DB |
|---|---|---|---|---|---|
| `/opt/inventarioarens-cloud` (Master) | `app.miinventariofacil.com` | `172.18.0.1:8080` | `/run/php/php8.4-fpm.sock` | `inventory_arens` | `postgres` |
| `/opt/balanzapro-cloud` | `app.balanzapro.com` | `172.18.0.1:8086` | `/run/php/php8.4-fpm-balanzapro.sock` | `inventory_balanzapro` | `balanzapro_app` |
| `/opt/tiendasarens-cloud` | `app.tiendasarens.com` | `172.18.0.1:8082` | `/run/php/php8.4-fpm-tiendasarens.sock` | `inventory_tiendasarens` | `tiendasarens_app` |
| `/opt/repuestosavilacar-cloud` | `app.repuestosavilacar.com` | `172.18.0.1:8084` | `/run/php/php8.4-fpm-repuestosavilacar.sock` | `inventory_repuestosavilacar` | `repuestosavilacar_app` |

---

## 2. Arquitectura de Red y Enrutamiento

```
Internet (HTTPS)
   │
   ▼
Traefik (Docker, puertos 80/443, TLS Let's Encrypt en acme.json)
   │
   ├─► Host(`app.miinventariofacil.com`)  ──► 172.18.0.1:8080 (Nginx inventarioarens)
   ├─► Host(`app.balanzapro.com`)          ──► 172.18.0.1:8086 (Nginx balanzapro)
   ├─► Host(`app.tiendasarens.com`)        ──► 172.18.0.1:8082 (Nginx tiendasarens)
   └─► Host(`app.repuestosavilacar.com`)   ──► 172.18.0.1:8084 (Nginx repuestosavilacar)
```

Nginx enruta internamente:
- `/api/*` hacia el socket correspondiente de `php8.4-fpm`.
- `/` y assets estáticos hacia `routes/web.php`, el cual sirve el bundle SPA React desde `frontend/dist/`.

---

## 3. Manejo de Frontend y Dependencias

- **Compilación de Frontend**:  
  Las dependencias de desarrollo (`node_modules` de pnpm) residen únicamente en la instancia canónica: `/opt/inventarioarens-cloud/frontend`.  
  Las demás instancias no cuentan con `node_modules` para optimizar almacenamiento en disco.
- **Distribución de Assets**:  
  Al compilar cambios en frontend (`pnpm run build` en `/opt/inventarioarens-cloud/frontend`), el contenido resultante en `frontend/dist/` se sincroniza hacia las otras 3 instancias.

---

## 4. Procedimiento de Sincronización entre Instancias

Dado que todas las carpetas están en el mismo servidor de archivos, la sincronización se realiza mediante **Git local directo** (Fast-Forward) sin depender de credenciales de GitHub en el VPS.

### Script de sincronización completa:

```bash
#!/usr/bin/env bash
set -euo pipefail

SOURCE_DIR="/opt/inventarioarens-cloud"
TARGETS=(
  "/opt/balanzapro-cloud:www-data:www-data"
  "/opt/tiendasarens-cloud:mavis:mavis"
  "/opt/repuestosavilacar-cloud:www-data:www-data"
)

echo "=== 1. Compilando frontend en source si hubo cambios ==="
# cd "$SOURCE_DIR/frontend" && pnpm run build

for target_entry in "${TARGETS[@]}"; do
  IFS=":" read -r target_dir target_user target_group <<< "$target_entry"
  echo "----------------------------------------------------"
  
  # Verificar rama activa: si no esta en 'main', protegerla de sobreescritura
  CURRENT_BRANCH=$(git -C "$target_dir" rev-parse --abbrev-ref HEAD)
  if [ "$CURRENT_BRANCH" != "main" ]; then
    echo ">> [SKIP] $target_dir esta en rama '$CURRENT_BRANCH'. No se sobreescribe con main."
    continue
  fi

  echo "Actualizando $target_dir ..."

  # 1. Pull Git local
  git -C "$target_dir" pull "$SOURCE_DIR" main --ff-only

  # 2. Sincronizar frontend/dist
  rsync -a --delete "$SOURCE_DIR/frontend/dist/" "$target_dir/frontend/dist/"
  chown -R "$target_user:$target_group" "$target_dir/frontend/dist"

  # 3. Migraciones de DB (si aplica)
  php "$target_dir/artisan" migrate --force

  # 4. Limpieza y refresco de caché Laravel
  php "$target_dir/artisan" optimize:clear
done

echo "----------------------------------------------------"
echo "=== Recargando PHP-FPM ==="
systemctl reload php8.4-fpm

echo "=== Verificando commits ==="
for target_entry in "${TARGETS[@]}"; do
  IFS=":" read -r target_dir _ _ <<< "$target_entry"
  echo -n "$target_dir: "
  git -C "$target_dir" log -1 --oneline
done

echo "=== Sincronización finalizada con éxito ==="
```

---

## 5. Reglas y Convenciones de Tenancy en Múltiples Instancias

1. **Capacidades por defecto**:  
   A partir del commit `99c9910`, `BaseCapabilities::DEFAULT_NEW = self::ALL`. Toda empresa nueva (grupo o empresa hija/spinoff) creada en cualquiera de las 4 instancias nace con las **22 capacidades habilitadas**.
2. **Roles y permisos Spatie**:  
   Cada base de datos (`inventory_arens`, `inventory_balanzapro`, `inventory_tiendasarens`, `inventory_repuestosavilacar`) gestiona sus propios tenants en su base de datos independiente.  
   La columna de equipo en Spatie es `tenant_id` (`config/permission.php`).
3. **Aislamiento**:  
   Modificar o restablecer una base de datos en una instancia no afecta en absoluto a las demás. Nunca ejecutar comandos destructivos (`migrate:fresh`, `vps_wipe.py`) sin especificar con certeza absoluta el directorio y la base de datos destino.

---

## 6. Instancias con Ramas Personalizadas (Repuestos Avilacar)

Cuando un cliente requiere cambios visuales o de interfaz exclusivos que no aplican al producto base, se aísla en su propia rama Git:

- **Instancia**: `/opt/repuestosavilacar-cloud` (`app.repuestosavilacar.com`)
- **Rama Git**: `client/repuestosavilacar`
- **Compilación de Frontend**:  
  Posee un enlace simbólico `frontend/node_modules -> /opt/inventarioarens-cloud/frontend/node_modules`, permitiendo compilar su propio bundle de React sin duplicar paquetes en disco.
- **Script de Despliegue de Avilacar**:  
  `/opt/repuestosavilacar-cloud/scripts/build-avilacar.sh`
  1. Compila el frontend local: `pnpm run build` en su propio directorio.
  2. Ajusta permisos: `chown -R www-data:www-data frontend/dist`.
  3. Limpia cachés de Laravel: `artisan optimize:clear`.
  4. Recarga `php8.4-fpm`.
- **Protección Automática**:  
  El script `scripts/sync-all-vps-instances.sh` inspecciona la rama activa de cada instancia. Al detectar que `/opt/repuestosavilacar-cloud` está en `client/repuestosavilacar`, omite el pull de `main` y la sobreescritura de `frontend/dist`.
- **Incorporar Mejoras de `main` a la Rama Personalizada**:  
  Si se publican correcciones en `main` que deban incorporarse a Avilacar:
  ```bash
  git -C /opt/repuestosavilacar-cloud merge /opt/inventarioarens-cloud/main
  bash /opt/repuestosavilacar-cloud/scripts/build-avilacar.sh
  ```

