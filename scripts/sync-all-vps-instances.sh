#!/usr/bin/env bash
# scripts/sync-all-vps-instances.sh
# Sincroniza las 3 instancias secundarias en el VPS desde /opt/inventarioarens-cloud.
set -euo pipefail

SOURCE_DIR="/opt/inventarioarens-cloud"
TARGETS=(
  "/opt/balanzapro-cloud:www-data:www-data"
  "/opt/tiendasarens-cloud:mavis:mavis"
  "/opt/repuestosavilacar-cloud:www-data:www-data"
)

echo "=== Sincronizando instancias en VPS desde $SOURCE_DIR ==="

for target_entry in "${TARGETS[@]}"; do
  IFS=":" read -r target_dir target_user target_group <<< "$target_entry"
  echo "----------------------------------------------------"
  # Verificar rama activa: si no esta en 'main', protegerla de sobreescritura
  CURRENT_BRANCH=$(git -C "$target_dir" rev-parse --abbrev-ref HEAD)
  if [ "$CURRENT_BRANCH" != "main" ]; then
    echo ">> [SKIP] $target_dir esta en rama '$CURRENT_BRANCH'. No se sobreescribe con main."
    continue
  fi

  # 1. Pull Git local Fast-Forward
  git -C "$target_dir" pull "$SOURCE_DIR" main --ff-only

  # 2. Sincronizar frontend/dist
  if [ -d "$SOURCE_DIR/frontend/dist" ]; then
    rsync -a --delete "$SOURCE_DIR/frontend/dist/" "$target_dir/frontend/dist/"
    chown -R "$target_user:$target_group" "$target_dir/frontend/dist"
  fi

  # 3. Migraciones de DB pendientes
  php "$target_dir/artisan" migrate --force

  # 4. Limpiar caches de Laravel
  php "$target_dir/artisan" optimize:clear
done

echo "----------------------------------------------------"
echo ">> Recargando servicio PHP-FPM..."
systemctl reload php8.4-fpm

echo "----------------------------------------------------"
echo ">> Resumen de versiones:"
for target_entry in "${TARGETS[@]}"; do
  IFS=":" read -r target_dir _ _ <<< "$target_entry"
  echo -n "$target_dir: "
  git -C "$target_dir" log -1 --oneline
done

echo ">> Proceso finalizado correctamente."
