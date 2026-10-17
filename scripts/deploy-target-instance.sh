#!/usr/bin/env bash
# scripts/deploy-target-instance.sh
# Despliega una rama/commit en una instancia específica del VPS (o todas).
#
# Uso:
#   bash scripts/deploy-target-instance.sh <target> <branch_or_ref> [opciones]
#
# Targets permitidos:
#   balanzapro | tiendasarens | repuestosavilacar | inventarioarens | all
#
# Opciones:
#   --dist-dir=<path>     Ruta a carpeta frontend/dist precompilada (ej: artifact de GitHub)
#   --skip-migrations    No ejecutar php artisan migrate --force
#   --skip-composer      No ejecutar composer install
#   --clean-checkout     Forzar checkout limpio (git reset --hard origin/<ref>)

set -euo pipefail

TARGET="${1:-}"
REF="${2:-main}"
shift 2 || true

if [ -z "$TARGET" ]; then
  echo "Error: Debes especificar el target (balanzapro|tiendasarens|repuestosavilacar|inventarioarens|all)."
  exit 1
fi

DIST_DIR=""
SKIP_MIGRATIONS=false
SKIP_COMPOSER=false
CLEAN_CHECKOUT=false

for arg in "$@"; do
  case "$arg" in
    --dist-dir=*)
      DIST_DIR="${arg#*=}"
      ;;
    --skip-migrations)
      SKIP_MIGRATIONS=true
      ;;
    --skip-composer)
      SKIP_COMPOSER=true
      ;;
    --clean-checkout)
      CLEAN_CHECKOUT=true
      ;;
    *)
      echo "Opción desconocida: $arg"
      exit 1
      ;;
  esac
done

resolve_instance_meta() {
  local inst="$1"
  case "$inst" in
    balanzapro)
      echo "/opt/balanzapro-cloud:www-data:www-data:8086:app.balanzapro.com"
      ;;
    tiendasarens)
      echo "/opt/tiendasarens-cloud:mavis:mavis:8082:app.tiendasarens.com"
      ;;
    repuestosavilacar)
      echo "/opt/repuestosavilacar-cloud:www-data:www-data:8084:app.repuestosavilacar.com"
      ;;
    inventarioarens)
      echo "/opt/inventarioarens-cloud:www-data:www-data:8080:app.miinventariofacil.com"
      ;;
    *)
      echo ""
      ;;
  esac
}

deploy_single_instance() {
  local inst="$1"
  local meta
  meta=$(resolve_instance_meta "$inst")

  if [ -z "$meta" ]; then
    echo "Error: Instancia desconocida '$inst'."
    exit 1
  fi

  IFS=":" read -r target_dir target_user target_group target_port target_domain <<< "$meta"

  echo "============================================================"
  echo "==> Desplegando en $inst ($target_domain)"
  echo "    Directorio: $target_dir"
  echo "    Rama/Ref:   $REF"
  echo "============================================================"

  if [ ! -d "$target_dir/.git" ]; then
    echo "Error: El directorio $target_dir no es un repositorio Git válido."
    exit 1
  fi

  cd "$target_dir"

  # 1. Fetch de origin
  echo ">> [1/6] Actualizando referencias remotas..."
  git fetch origin "$REF" --prune

  # 2. Verificar estado del working tree
  local dirty_files
  dirty_files=$(git status --porcelain 2>/dev/null | wc -l)
  if [ "$dirty_files" -gt 0 ]; then
    if [ "$CLEAN_CHECKOUT" = true ]; then
      echo ">> [AVISO] Se detectaron cambios locales no commiteados. --clean-checkout activo: limpiando..."
      git reset --hard "origin/$REF"
      git clean -fd -e "storage/" -e ".env"
    else
      echo ">> [ERROR] El working tree en $target_dir tiene $dirty_files cambios sin commitear."
      echo "   Para no perder cambios, el despliegue se cancela. Usa --clean-checkout si deseas forzar."
      exit 1
    fi
  else
    git checkout "$REF"
    git merge --ff-only "origin/$REF" || git reset --hard "origin/$REF"
  fi

  # 3. Frontend precompilado (si se suministró)
  if [ -n "$DIST_DIR" ] && [ -d "$DIST_DIR" ]; then
    echo ">> [2/6] Sincronizando frontend precompilado desde $DIST_DIR..."
    mkdir -p "$target_dir/frontend/dist"
    rsync -a --delete "$DIST_DIR/" "$target_dir/frontend/dist/"
    chown -R "$target_user:$target_group" "$target_dir/frontend/dist"
  else
    echo ">> [2/6] Sin dist-dir suministrado; se conserva el bundle frontend existente."
  fi

  # 4. Composer install (si no se omite)
  if [ "$SKIP_COMPOSER" = false ]; then
    echo ">> [3/6] Verificando dependencias Composer..."
    if git diff HEAD@{1} --name-only -- composer.lock 2>/dev/null | grep -q composer.lock; then
      echo "   composer.lock cambió: ejecutando composer install..."
      composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
    else
      echo "   composer.lock sin cambios recientes; saltando install."
    fi
  else
    echo ">> [3/6] Composer install omitido (--skip-composer)."
  fi

  # 5. Migraciones de DB
  if [ "$SKIP_MIGRATIONS" = false ]; then
    echo ">> [4/6] Ejecutando migraciones de base de datos..."
    php artisan migrate --force
  else
    echo ">> [4/6] Migraciones omitidas (--skip-migrations)."
  fi

  # 6. Caches y optimizaciones
  echo ">> [5/6] Refrescando cachés de Laravel..."
  php artisan optimize:clear
  php artisan config:cache
  php artisan route:cache

  # 7. Permisos de carpetas críticas
  chown -R "$target_user:$target_group" storage bootstrap/cache

  # 8. Healthcheck
  echo ">> [6/6] Verificando salud HTTP de la instancia..."
  local http_code
  http_code=$(curl -sS -o /dev/null -w "%{http_code}" -H "Host: $target_domain" "http://127.0.0.1:${target_port}/" || echo "000")
  echo "   Respuesta HTTP: $http_code (Host: $target_domain en 127.0.0.1:$target_port)"

  local current_commit
  current_commit=$(git rev-parse --short HEAD)
  echo ">> [OK] $inst desplegada con éxito en commit $current_commit."
}

if [ "$TARGET" = "all" ]; then
  INSTANCES=("balanzapro" "tiendasarens" "repuestosavilacar" "inventarioarens")
  for inst in "${INSTANCES[@]}"; do
    deploy_single_instance "$inst"
  done
else
  deploy_single_instance "$TARGET"
fi

echo "============================================================"
echo ">> Recargando PHP-FPM..."
systemctl reload php8.4-fpm
echo ">> Despliegue completado con éxito."
