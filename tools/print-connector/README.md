# Inventario Arens Print Connector

Independent Electron client for Windows printer delivery. It makes outbound HTTPS requests to the cloud API and does not open an HTTP port or depend on the Motor Local.

## User flow

1. Download and install `InventarioArens-Print-Connector-Setup-<version>.exe`, or use the portable executable.
2. Open **Print Connector**. The installer also creates a desktop shortcut and starts the client after installation.
3. Generate a pairing code in the online **Printing** screen.
4. Enter the cloud URL, pairing code and computer name in the GUI, then select **Vincular con la nube**.
5. Configure the printer station in the online **Printing** screen. The connector keeps polling in the background and appears in the Windows tray.

The default cloud URL is `https://app.miinventariofacil.com/api`. It can be changed in the GUI for another environment.

The token is stored in the application data folder and is never shown in the interface. Closing the window hides the client in the tray; use **Salir** from the tray menu to stop it.

## Development

The connector core can still be exercised directly during development:

```text
pnpm install
pnpm test
pnpm run build:gui:dir
```

`build:gui:dir` creates the unpacked Electron app under `build/print-connector-gui/win-unpacked`. Creating the Windows installer requires `windows-latest` or a local Windows environment.

The connector polls the cloud queue, claims one job, prints it through the configured Windows or TCP printer, and acknowledges `printed` or `failed`. A failed job remains eligible for a later retry after the cloud claim lease expires.

## Release

```text
pnpm run build:gui:win
```

The GitHub Actions workflow builds and publishes both `InventarioArens-Print-Connector-Portable-<version>.exe` and `InventarioArens-Print-Connector-Setup-<version>.exe`, plus SHA-256 files, under `v<version>-connector`.

The old Node SEA packager and scheduled-task scripts remain only as migration artifacts. They are not included in the Electron package or used by the release workflow.
