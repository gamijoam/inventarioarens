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

# 6. image-optimizer (Optimizador y redimensionador ultrarrapido de imagenes)
echo "--> Compilando image-optimizer..."
cd "${ROOT_DIR}/tools/image-optimizer"
mkdir -p bin
go build -ldflags="-s -w" -o bin/image-optimizer ./cmd/image-optimizer
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/image-optimizer.exe ./cmd/image-optimizer
echo "    [OK] image-optimizer (Linux & Windows .exe)"

# 7. pdf-engine (Generador ultrarrapido de tickets y facturas PDF)
echo "--> Compilando pdf-engine..."
cd "${ROOT_DIR}/tools/pdf-engine"
mkdir -p bin
go build -ldflags="-s -w" -o bin/pdf-engine ./cmd/pdf-engine
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/pdf-engine.exe ./cmd/pdf-engine
echo "    [OK] pdf-engine (Linux & Windows .exe)"

# 8. barcode-engine (Generador de codigos de barra y QR)
echo "--> Compilando barcode-engine..."
cd "${ROOT_DIR}/tools/barcode-engine"
mkdir -p bin
go build -ldflags="-s -w" -o bin/barcode-engine ./cmd/barcode-engine
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/barcode-engine.exe ./cmd/barcode-engine
echo "    [OK] barcode-engine (Linux & Windows .exe)"

# 9. watchdog (Guardian y auto-recuperador de procesos en memoria)
echo "--> Compilando watchdog..."
cd "${ROOT_DIR}/tools/watchdog"
mkdir -p bin
go build -ldflags="-s -w" -o bin/watchdog ./cmd/watchdog
CGO_ENABLED=0 GOOS=windows GOARCH=amd64 go build -ldflags="-s -w" -o bin/watchdog.exe ./cmd/watchdog
echo "    [OK] watchdog (Linux & Windows .exe)"

echo "==> Compilación finalizada con éxito."
ls -lh "${ROOT_DIR}"/tools/*/bin/*
