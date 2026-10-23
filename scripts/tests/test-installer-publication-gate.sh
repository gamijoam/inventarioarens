#!/usr/bin/env bash
set -euo pipefail

for variable in WINDOWS_CLEAN_INSTALL_VERIFIED TENANT_IMPORT_VERIFIED LAN_INSTALL_VERIFIED SCALE_HARDWARE_VERIFIED; do
  unset "$variable" || true
done

set +e
output="$(bash "$(dirname -- "${BASH_SOURCE[0]}")/../build-installer-direct.sh" --publish 2>&1)"
status=$?
set -e

if [[ "$status" -ne 3 ]]; then
  printf 'Expected publication gate to exit 3, got %s.\n%s\n' "$status" "$output" >&2
  exit 1
fi
if [[ "$output" != *'WINDOWS_CLEAN_INSTALL_VERIFIED=1'* ]]; then
  printf 'Publication gate did not identify the first missing verification.\n%s\n' "$output" >&2
  exit 1
fi

echo 'All-in-one installer publication remains blocked without real verification.'
