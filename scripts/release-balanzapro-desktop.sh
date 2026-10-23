#!/usr/bin/env bash
# Solicita candidatos Electron y Motor o publica candidatos previamente probados.
#
# Candidato:
#   GITHUB_TOKEN=... bash scripts/release-balanzapro-desktop.sh
#
# Publicación tras validar en Windows:
#   PUBLISH=true ACCEPTANCE_VERIFIED=true \
#   ELECTRON_CANDIDATE_RUN_ID=123 MOTOR_CANDIDATE_RUN_ID=456 \
#   GITHUB_TOKEN=... bash scripts/release-balanzapro-desktop.sh
set -euo pipefail

REPO="gamijoam/inventarioarens"
BRANCH="${BRANCH:-client/balanzapro}"
MOTOR_VERSION="${MOTOR_VERSION:-0.1.5}"
PUBLISH="${PUBLISH:-false}"
ACCEPTANCE_VERIFIED="${ACCEPTANCE_VERIFIED:-false}"
ELECTRON_CANDIDATE_RUN_ID="${ELECTRON_CANDIDATE_RUN_ID:-}"
MOTOR_CANDIDATE_RUN_ID="${MOTOR_CANDIDATE_RUN_ID:-}"

: "${GITHUB_TOKEN:?Define GITHUB_TOKEN con permisos de Actions para el repositorio}"

if [[ "$PUBLISH" == "true" ]]; then
  [[ "$ACCEPTANCE_VERIFIED" == "true" ]] || { echo "Falta confirmar las pruebas reales de Windows." >&2; exit 2; }
  [[ "$ELECTRON_CANDIDATE_RUN_ID" =~ ^[0-9]+$ ]] || { echo "Falta ELECTRON_CANDIDATE_RUN_ID." >&2; exit 2; }
  [[ "$MOTOR_CANDIDATE_RUN_ID" =~ ^[0-9]+$ ]] || { echo "Falta MOTOR_CANDIDATE_RUN_ID." >&2; exit 2; }
fi

api() {
  local workflow="$1"
  shift
  curl --fail -sS -X POST \
    -H "Authorization: Bearer ${GITHUB_TOKEN}" \
    -H 'Accept: application/vnd.github+json' \
    -H 'X-GitHub-Api-Version: 2022-11-28' \
    -H 'Content-Type: application/json' \
    "https://api.github.com/repos/${REPO}/actions/workflows/${workflow}/dispatches" \
    -d "$1"
}

if [[ "$PUBLISH" == "true" ]]; then
  echo "==> Publicando el candidato Electron probado (run ${ELECTRON_CANDIDATE_RUN_ID})"
  api 'release-balanzapro.yml' "{\"ref\":\"${BRANCH}\",\"inputs\":{\"ref\":\"${BRANCH}\",\"publish\":\"true\",\"acceptance_verified\":\"true\",\"candidate_run_id\":\"${ELECTRON_CANDIDATE_RUN_ID}\"}}"
  echo "==> Publicando Motor ${MOTOR_VERSION} probado (run ${MOTOR_CANDIDATE_RUN_ID})"
  api 'release-motor.yml' "{\"ref\":\"${BRANCH}\",\"inputs\":{\"version\":\"${MOTOR_VERSION}\",\"variant\":\"balanzapro\",\"ref\":\"${BRANCH}\",\"publish\":\"true\",\"acceptance_verified\":\"true\",\"candidate_run_id\":\"${MOTOR_CANDIDATE_RUN_ID}\",\"prerelease\":\"false\"}}"
else
  echo "==> Creando candidato Electron desde ${BRANCH}"
  api 'release-balanzapro.yml' "{\"ref\":\"${BRANCH}\",\"inputs\":{\"ref\":\"${BRANCH}\",\"publish\":\"false\",\"acceptance_verified\":\"false\"}}"
  echo "==> Creando candidato Motor ${MOTOR_VERSION} desde ${BRANCH}"
  api 'release-motor.yml' "{\"ref\":\"${BRANCH}\",\"inputs\":{\"version\":\"${MOTOR_VERSION}\",\"variant\":\"balanzapro\",\"ref\":\"${BRANCH}\",\"publish\":\"false\",\"acceptance_verified\":\"false\",\"prerelease\":\"false\"}}"
fi

echo "Solicitudes enviadas. Revisa https://github.com/${REPO}/actions para obtener los run IDs."
