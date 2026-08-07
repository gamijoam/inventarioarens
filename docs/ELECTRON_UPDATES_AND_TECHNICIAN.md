# Electron Updates and Technician Client

## Current Clients

The repository builds three Windows/Linux Electron clients:

| Client | Mode | Update channel | Main function |
| --- | --- | --- | --- |
| Administrative | `admin` | `admin` | Business administration |
| POS | `pos` | `pos` | Point of sale |
| Technical Support | `technician` | `technician` | Local installation and sync support |

All clients include the React bundle, Laravel, PHP portable and the local SQLite runtime. Persistent
data is stored outside the installation directory, so replacing the installed application does not
replace the local database, sync tokens, logs or storage.

## Automatic Updates

`electron-updater` is configured with GitHub Releases. Each client uses its own channel so that the
Administrative, POS and Technical Support artifacts do not overwrite each other's update metadata.

The updater:

- is disabled in development and in the detached runtime supervisor;
- checks after startup in packaged builds;
- downloads a new version in the background;
- asks whether to restart immediately after the download;
- installs automatically on the next application exit if the user chooses to continue working.

The update does not stop the VPS and does not modify the VPS backend. It replaces only the desktop
application and its bundled local runtime. The local runtime runs migrations when it starts.

## First Update Bootstrap

Installers already distributed before this feature do not contain `electron-updater`. They require one
manual installation of a release that contains the updater. After that bootstrap release, subsequent
versions can be downloaded and installed automatically.

For a release, increment the version in `frontend/package.json`, run the relevant build commands, and
publish all three channel artifacts to the same GitHub release:

```powershell
cd frontend
pnpm install --frozen-lockfile
pnpm test
pnpm run electron:build:admin
pnpm run electron:build:pos
pnpm run electron:build:technician
```

The Windows artifacts are in `frontend/release/{admin,pos,technician}`. Code signing should be added
before production distribution to reduce SmartScreen warnings and make updates trustworthy.

## Technical Support Client

The Technical Support client opens the existing local support console at `/support`. It shares the local
Laravel runtime and SQLite data root with Administrative and POS, while keeping an isolated renderer
port and Electron user-data directory.

It supports the existing local operations:

- pairing a company with a one-time code;
- preparing the local tenant and initial snapshot;
- viewing sync metrics and errors;
- running sync and retrying failed inbox events;
- installing, starting, stopping and repairing Windows workers.

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
