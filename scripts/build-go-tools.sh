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

# 3. scale-agent (BalanzaPro)
echo "--> Compilando scale-agent (BalanzaPro)..."
cd "${ROOT_DIR}/tools/scale-agent"
mkdir -p bin
go build -ldflags="-s -w" -o bin/scale-agent ./cmd/scale-agent
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/scale-agent.exe ./cmd/scale-agent
echo "    [OK] scale-agent (Linux & Windows .exe)"

# 4. catalog-search (Buscador ultrarrapido en memoria)
echo "--> Compilando catalog-search..."
cd "${ROOT_DIR}/tools/catalog-search"
mkdir -p bin
go build -ldflags="-s -w" -o bin/catalog-search ./cmd/catalog-search
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/catalog-search.exe ./cmd/catalog-search
echo "    [OK] catalog-search (Linux & Windows .exe)"

# 5. ws-hub (Servidor de WebSockets en tiempo real multitenant)
echo "--> Compilando ws-hub..."
cd "${ROOT_DIR}/tools/ws-hub"
mkdir -p bin
go build -ldflags="-s -w" -o bin/ws-hub ./cmd/ws-hub
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/ws-hub.exe ./cmd/ws-hub
echo "    [OK] ws-hub (Linux & Windows .exe)"

echo "==> Compilación finalizada con éxito."
ls -lh "${ROOT_DIR}"/tools/*/bin/*
