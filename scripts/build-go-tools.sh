#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Compilando herramientas en Go para Linux y Windows..."

# 1. sync-daemon
echo "--> Compilando sync-daemon..."
cd "${ROOT_DIR}/tools/sync-daemon"
mkdir -p bin
go build -ldflags="-s -w" -o bin/sync-daemon ./cmd/sync-daemon
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/sync-daemon.exe ./cmd/sync-daemon
echo "    [OK] sync-daemon (Linux & Windows .exe)"

# 2. printer-agent
echo "--> Compilando printer-agent..."
cd "${ROOT_DIR}/tools/printer-agent"
mkdir -p bin
go build -ldflags="-s -w" -o bin/printer-agent ./cmd/printer-agent
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/printer-agent.exe ./cmd/printer-agent
echo "    [OK] printer-agent (Linux & Windows .exe)"

echo "==> Compilación finalizada con éxito."
ls -lh "${ROOT_DIR}"/tools/*/bin/*
