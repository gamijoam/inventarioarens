# Conector De Impresion Cloud

Actualizado: 2026-08-25

## Objetivo

El Conector de Impresion es un proceso independiente para cada instalacion Windows. La aplicacion online crea el trabajo en la nube y el conector lo recoge mediante HTTPS saliente. No abre puertos, no expone `127.0.0.1:17777` y no depende del Motor Local.

Flujo:

```text
POS online -> print_jobs en la nube -> polling del conector -> impresora local -> ACK
```

## Vinculacion

1. Un usuario con `printing.manage` genera `POST /api/printing/connectors/pairing-codes`.
2. El codigo se muestra una sola vez y expira en 10 minutos.
3. El instalador ejecuta `node connector.cjs register CODE "Nombre de caja" "https://app.miinventariofacil.com/api"`.
4. La nube consume el codigo una sola vez y devuelve un token exclusivo para esa instalacion.
5. El token se guarda en `%ProgramData%\InventarioArens\PrintConnector\config.json` y nunca se muestra en `status`.

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
- Conector independiente: `tools/print-connector/connector.cjs`.
- Pruebas del conector: `tools/print-connector/connector.test.cjs`.
- Empaquetado standalone: `tools/print-connector/package-connector.cjs`.
- Instalador Windows: `tools/print-connector/PrintConnector.iss`.

Pruebas locales:

```text
cd tools/print-connector
npm test
npm run package:check
```

El workflow `.github/workflows/release-print-connector.yml` construye en `windows-latest` y publica el ejecutable portable, el instalador y sus hashes en `v<version>-connector`.
