#!/usr/bin/env bash
# Dispara los releases Electron y del Motor para BalanzaPro en GitHub.
#
# Requiere un token con permisos 'repo' + 'workflow':
#   export GITHUB_TOKEN=ghp_xxx
#   bash scripts/release-balanzapro-desktop.sh
#
# Opcionales:
#   BRANCH=client/balanzapro   MOTOR_VERSION=0.1.0
set -euo pipefail

REPO="gamijoam/inventarioarens"
BRANCH="${BRANCH:-client/balanzapro}"
MOTOR_VERSION="${MOTOR_VERSION:-0.1.0}"
CLIENTS=("balanzapro-pos" "balanzapro-admin")

: "${GITHUB_TOKEN:?Exporta GITHUB_TOKEN con scopes 'repo' y 'workflow'}"

API_ROOT="https://api.github.com"

api() {
  local method="$1"
  local path="$2"
  shift 2
  curl -sS -X "$method" \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H "Accept: application/vnd.github+json" \
    -H "X-GitHub-Api-Version: 2022-11-28" \
    -H "Content-Type: application/json" \
    "${API_ROOT}${path}" "$@"
}

echo "==> Subiendo $BRANCH a $REPO"
git push "https://${GITHUB_TOKEN}@github.com/${REPO}.git" "$BRANCH"

echo "==> Disparando release.yml por cliente"
for client in "${CLIENTS[@]}"; do
  api POST "/repos/${REPO}/actions/workflows/release.yml/dispatches" \
    -d "{\"ref\":\"${BRANCH}\",\"inputs\":{\"client\":\"${client}\"}}"
  echo "   - ${client}"
done

echo "==> Disparando release-motor.yml (variant=balanzapro)"
api POST "/repos/${REPO}/actions/workflows/release-motor.yml/dispatches" \
  -d "{\"ref\":\"${BRANCH}\",\"inputs\":{\"version\":\"${MOTOR_VERSION}\",\"variant\":\"balanzapro\",\"ref\":\"${BRANCH}\",\"prerelease\":\"false\"}}"

VERSION="$(node -p "require('./frontend/package.json').version")"

echo
echo "==> Runs: https://github.com/${REPO}/actions"
echo "==> Releases esperados cuando terminen:"
echo "    POS:   https://github.com/${REPO}/releases/tag/v${VERSION}-balanzapro-pos"
echo "    Admin: https://github.com/${REPO}/releases/tag/v${VERSION}-balanzapro-admin"
echo "    Motor: https://github.com/${REPO}/releases/tag/motor-v${MOTOR_VERSION}-balanzapro"
