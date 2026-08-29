# Conector De Impresion Cloud

Actualizado: 2026-08-28

Guia para usuarios: `docs/GUIA_USUARIO_CONECTOR_IMPRESION.md`.

## Objetivo

El Conector de Impresion es un proceso independiente para cada instalacion Windows. La aplicacion online crea el trabajo en la nube y el conector lo recoge mediante HTTPS saliente. No abre puertos, no expone `127.0.0.1:17777` y no depende del Motor Local.

Flujo:

```text
POS online -> print_jobs en la nube -> polling del conector -> impresora local -> ACK
```

## Vinculacion

1. Un usuario con `printing.manage` genera `POST /api/printing/connectors/pairing-codes`.
2. El codigo se muestra una sola vez y expira en 10 minutos.
3. El usuario instala `InventarioArens-Print-Connector-Setup-<version>.exe` o ejecuta el portable.
4. La ventana **Print Connector** recibe la URL de la nube, el codigo y el nombre de la caja.
5. Al seleccionar **Vincular con la nube**, la nube consume el codigo una sola vez y devuelve un token exclusivo para esa instalacion.
6. El token se guarda en la carpeta de datos de la aplicacion y nunca se muestra en la interfaz.
7. El cliente queda en segundo plano, con acceso desde el icono de la bandeja y arranque automatico del usuario.

La URL predeterminada es `https://app.miinventariofacil.com/api`. El usuario no necesita abrir una consola ni ejecutar scripts para instalar o vincular el conector.

## Transporte

El conector usa exclusivamente:

- `GET /api/printing/connector/heartbeat`
- `GET /api/printing/connector/jobs`
- `POST /api/printing/connector/jobs/{uuid}/claim`
- `GET /api/printing/connector/jobs/{uuid}/ticket.pdf`
- `POST /api/printing/connector/jobs/{uuid}/ack`

Cada trabajo se reclama con una lease de dos minutos. Un fallo de red o proceso deja el trabajo disponible después de expirar la lease. El ACK final usa `printed` o `failed`.

## Impresion

La primera implementacion soporta:

- Impresora Windows mediante `Out-Printer`.
- Impresora de red mediante TCP 9100 y ESC/POS.
- Corte de papel y apertura de gaveta cuando el perfil lo solicita.
- Cola de tickets POS con snapshot inmutable del ticket.

Los trabajos `thermal` con estacion vinculada a un conector ya no se envian desde React al agente local. Los trabajos `digital` se descargan como PDF desde el navegador y pueden guardarse o imprimirse manualmente.

## Codigo

- Backend: `app/Modules/Printing/`.
- Migracion: `database/migrations/2026_08_25_180000_create_print_connectors_table.php`.
- Core del conector: `tools/print-connector/connector.cjs`.
- Cliente Electron: `tools/print-connector/main.cjs`, `preload.cjs` y `renderer/`.
- Empaquetado Electron: `tools/print-connector/electron-builder.yml`.
- Pruebas del conector y GUI: `tools/print-connector/{connector.test.cjs,gui.test.cjs}`.
- Workflow de release: `.github/workflows/release-print-connector.yml`.
- Empaquetador Node SEA e instalador Inno Setup: legado de migracion; no forman parte del release GUI.

Pruebas locales:

```text
cd tools/print-connector
pnpm install
pnpm test
pnpm run build:gui:dir
```

El workflow `.github/workflows/release-print-connector.yml` construye en `windows-latest` y publica el ejecutable portable, el instalador Electron y sus hashes en `v<version>-connector`.
