# Inventario Arens Print Connector

Independent local service for Windows printer delivery. It makes outbound HTTPS requests to the cloud API and does not open an HTTP port or depend on the Motor Local.

## Register

Generate a pairing code in the Printing screen, then run:

```text
node connector.cjs register CODE "Caja Principal"
```

The token is stored in the platform data directory. The token is never printed by `status`.

## Run

```text
node connector.cjs run
```

The connector polls the cloud queue, claims one job, prints it through the configured Windows or TCP printer, and acknowledges `printed` or `failed`. A failed job remains eligible for a later retry after the cloud claim lease expires.

The configuration path can be overridden with `PRINT_CONNECTOR_CONFIG`. The default is `%ProgramData%\InventarioArens\PrintConnector\config.json` on Windows.

## Tests

```text
npm test
npm run package:check
npm run package -- --version 0.1.0
```

The package command creates a standalone executable under `build/print-connector/stage`. The Windows GitHub Actions workflow then compiles `PrintConnector.iss` and publishes both the portable `.exe` and the Inno Setup installer in the release tag `v<version>-connector`.
