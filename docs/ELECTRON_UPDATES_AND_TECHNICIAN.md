# Electron Updates and Technician Client

## Current Clients

The repository builds three Windows/Linux Electron clients:

| Client | Mode | Update channel | Main function |
| --- | --- | --- | --- |
| Administrative | `admin` | `admin` | Business administration |
| POS | `pos` | `pos` | Point of sale |
| Technical Support | `technician` | `technician` | Local installation and sync support |

All clients include the React bundle, Laravel, PHP portable and the local SQLite runtime. Persistent
data is stored outside the installation directory (the shared `InventarioArens` data root under
`%APPDATA%`), so replacing the installed application does not replace the local database, sync tokens,
logs or storage.

Each client installs into its own per-user folder (`oneClick: false` + `executableName`). This stops
the Administrative/POS/Technician installers from landing in the same `inventarioarens-frontend`
folder and overwriting each other's `app.asar`.

## Automatic Updates

`electron-updater` is configured with GitHub Releases. Each client uses its own channel
(`admin` / `pos` / `technician`) so artifacts do not overwrite each other's update metadata. The
update metadata file is `<channel>.yml` (e.g. `pos.yml`), not `latest.yml`.

The updater:

- is disabled in development and in the detached runtime supervisor;
- checks after startup in packaged builds and then every **1 minute** (constant
  `UPDATE_CHECK_INTERVAL_MS` in `frontend/electron/auto-updater.cjs`) — set short for faster change
  verification during development;
- downloads a new version in the background;
- asks whether to restart immediately after the download;
- installs automatically on the next application exit if the user chooses to continue working.

The update does not stop the VPS and does not modify the VPS backend. It replaces only the desktop
application and its bundled local runtime. The local runtime runs migrations when it starts.

## Release Workflow (GitHub Actions)

`.github/workflows/release.yml` builds and publishes a client automatically. Trigger it with:

```bash
gh workflow run release.yml -f client=pos        # or admin / technician
```

The workflow:

1. checks out, installs composer deps and frontend deps on `windows-latest`;
2. runs `pnpm run build:<client>` (tsc + vite build), `electron:stage-backend`, then
   `electron-builder` **without** `--publish` (electron-builder + GITHUB_TOKEN leaves drafts and can
   drop the large installer);
3. publishes explicitly with `gh release create v<version>-<client>` (non-draft) and uploads the
   `.exe`, `.blockmap` and `<channel>.yml`.

Note the tag is `v<version>-<client>` (e.g. `v0.2.3-pos`) so the three clients can have the same
`package.json` version without colliding on the tag.

### Important build rules

- The published installer must be produced from a current build. `pnpm run build:<client>` regenerates
  `dist/<client>`; then `electron-builder` packages it. If you run `electron-builder` alone without
  regenerating `dist`, it packages a stale bundle and the released app does not include the latest UI
  change.
- If the GitHub release already exists, the workflow deletes it (`gh release delete --cleanup-tag`)
  and recreates it, so a re-publish replaces the old installer.

### Manual publish fallback

If you need to publish from a local machine (faster, avoids CI empaquetado bugs):

```bash
cd frontend
pnpm run build:pos
pnpm exec electron-builder --config electron-builder.pos.yml
cd release/pos
gh release create v<version>-pos --repo gamijoam/inventarioarens --title "<version>" \
  Sistema-de-Inventario-POS-<version>.exe Sistema-de-Inventario-POS-<version>.exe.blockmap pos.yml
```

## Version Bumping

For every release bump the version in `frontend/package.json` (semver). electron-updater only
updates when the published version is higher than the installed one. Re-publishing the same version
will not reinstall on already-updated machines.

## First Update Bootstrap

Installers distributed before `electron-updater` was added do not contain the updater. They require
one manual installation of a release that contains the updater. After that bootstrap release,
subsequent versions are downloaded and installed automatically.

## Technical Support Client and Background Sync Workers

### Workers are Windows Scheduled Tasks (app-independent)

Background sync runs through one Windows Scheduled Task per company, created by the technician when a
group is linked. Each task runs every **1 minute** and executes `sync-worker.cmd run -TenantSlug
<slug>`, which calls `php artisan sync:run` (one push+pull+apply cycle). This is independent of the
Electron app: sync keeps working with the technician/POS/admin closed.

Key pieces:

- `LocalTechnicalConsoleService::installWindowsWorkerTaskQuiet` installs the task during group link.
- `workerLauncherContent` writes a `.cmd` that sets `LARAVEL_STORAGE_PATH`, `DB_DATABASE` (the data
  root sqlite) and `PHP_INI_SCAN_DIR`, then runs `sync-worker.cmd run ...` with `-PhpPath`.
- `workerTaskCommand` uses **short (8.3) paths** so the `schtasks /TR` command stays under the
  261-character limit (long per-client install folders previously exceeded it).
- The Electron supervisor no longer spawns sync daemons; sync lives entirely in the scheduled task.

The technician UI no longer shows start/stop/restart worker buttons; it shows a badge that background
sync is active while the app is open. Reinstalling the client does not remove the scheduled tasks
(they are per-user Windows tasks pointing at the installed path).

### Sync direction

- **Local -> Cloud**: the push sends `product.*`, `exchange_rate.*`, etc. `applyProduct` matches the
  target row by `sku` (or `catalog_product_id` when present), not by "first product of tenant".
- **Cloud -> Children**: the shared catalog propagation (`SharedCatalogPropagationService`) emits a
  `sync_outbox` event under each child tenant after copying products, exchange rates, brands, tags,
  price lists, exchange rate types, payment methods, warranties and suppliers, so each child's local
  node pulls the change.
- A background daemon on the VPS (`inventarioarens-sync.timer`, every 15s) applies pending inboxes
  for all tenants; ACKs are sent concurrently (`Http::pool`) to avoid per-cycle timeouts.

### Known operational notes

- `sync:prepare-local` replicates `is_group` and `parent_id` from the remote group so the local
  tenant hierarchy mirrors the cloud (group root vs spinoffs).
- If a child snapshot is incomplete because thousands of duplicate `initial-snapshot` events pile up,
  cleaning the redundant pending snapshot events in the VPS outbox lets the worker reach the real
  changes immediately.
- Scheduled tasks keep the DB open; manual SQLite writes may hit `database is locked` while a worker
  runs. Stop the tasks temporarily before large local DB fixes.

## CI

`.github/workflows/ci.yml` runs only the frontend job (tsc + vitest). The PHPUnit Feature suite is
validated locally with `phpunit.sqlite.xml`; running it in CI with PostgreSQL took 15+ minutes and
had pre-existing failures (180s `set_time_limit`, heavy demo seeders), so it was removed from CI.
The demo seeder tests are tagged `@group heavy`.

## LAN Server Mode

The local API remains bound to `127.0.0.1` by default. The Technical Support client can enable an
explicit LAN server mode. The setting is written to the shared data root and requires restarting the
Electron clients. The host client must remain open while remote clients use the installation.

When LAN mode is enabled, the host exposes these renderer URLs on the private network:

```text
http://HOST-IP:8788  Administrative
http://HOST-IP:8789  POS
http://HOST-IP:8790  Technical Support (normally keep this local)
```

The renderers proxy API requests to the local Laravel process. The technical console endpoints remain
available only through loopback. Regular API requests still require normal user authentication and
tenant headers.

Windows Firewall should be opened only on the Private profile and only for the local subnet. Example
PowerShell command, run as Administrator on the host:

```powershell
New-NetFirewallRule `
  -DisplayName "Inventario Arens LAN" `
  -Direction Inbound `
  -Action Allow `
  -Protocol TCP `
  -LocalPort 8787,8788,8789 `
  -Profile Private `
  -RemoteAddress LocalSubnet
```

Remove the rule to disable LAN access:

```powershell
Remove-NetFirewallRule -DisplayName "Inventario Arens LAN"
```

Do not share `inventario.sqlite` through SMB and do not expose PostgreSQL. Remote clients must use the
HTTP API/renderer, never the database file.
