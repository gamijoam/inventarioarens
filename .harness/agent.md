---
name: inventarioarens
description: INVENTARIOARENS multi-tenant Laravel 13 inventory + POS SaaS — runs locally on Windows (Laragon + PHP 8.4) and syncs bidirectionally with a Laravel backend on a Contabo VPS at 217.216.80.158, reachable publicly at https://app.miinventariofacil.com/api.
---

# INVENTARIOARENS project harness

This harness owns the INVENTARIOARENS project — a multi-tenant Laravel 13 inventory + POS SaaS
that runs locally on Windows (Laragon + PHP 8.4) and syncs bidirectionally with a Laravel
backend on a Contabo VPS.

## Stack at a glance

- **Local**: Windows + Laragon + PHP 8.4.23 (`C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe`).
  Composer 2.x. PostgreSQL 16 local on `127.0.0.1:5434`, DB `inventory_arens`, user `inventory_arens`/`secret`.
- **Cloud backend**: Laravel 13, served by Nginx + PHP-FPM at `/opt/inventarioarens-cloud/public` on the VPS.
  PostgreSQL 16 (NOT Docker, installed directly on the host) on `127.0.0.1:5432`, DB `inventory_arens`, user `postgres`.
- **Frontend**: Vite + React + Inertia (`frontend_web/`), Livewire/Tailwind admin panel, landing page.
- **Cloud public URL**: `https://app.miinventariofacil.com/api` (DNS A record → 217.216.80.158, Nginx + Let's Encrypt).
- **No Traefik, no Docker, no containerd on this VPS.** The other Contabo VPS (212.28.176.157) IS Docker-based
  but hosts a DIFFERENT project (MiInventarioFácil) — do NOT confuse them.

## Infrastructure — the right one

- **VPS IP**: `217.216.80.158` (host: `vmi3267687`, Ubuntu 24.04.4 LTS, Contabo)
- **VPS SSH keys** (under `C:\Users\gafit\.ssh\`): the key matching this VPS is `webadmin-vps` (user `webadmin`).
  `bloqueo_vps_mavis` and `fatrans-vps` are for OTHER servers (the MiInventarioFácil VPS and a third one).
- **Local PHP path** (full, not on PowerShell PATH): `C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe`
- **Sync cloud URL** (from `.env`): `SYNC_CLOUD_URL=https://app.miinventariofacil.com/api`
  — the `app.miinventariofacil.com` domain DOES serve THIS project's Laravel backend, despite the
  `miinventariofacil` brand. The QA/staging of the OTHER project (MiInventarioFácil) lives at
  `app-qa.miinventariofacil.com` on a different VPS.

## Do NOT confuse with the OTHER project

The user has TWO Laravel-adjacent SaaS products that share a brand and even a domain. They are
on DIFFERENT VPSs with DIFFERENT DBs and DIFFERENT stacks:

| Project            | VPS              | Backend            | DBs            | SSH key             |
|--------------------|------------------|--------------------|----------------|---------------------|
| INVENTARIOARENS    | 217.216.80.158   | Laravel 13         | inventory_arens| webadmin-vps        |
| MiInventarioFácil | 212.28.176.157   | FastAPI 2.2.0 (QA) | invensoft_qa / invensoft_prod | bloqueo_vps_mavis   |

**The previous session confused these two projects.** It SSH'd to `212.28.176.157` and queried
`invensoft_qa` thinking it was INVENTARIOARENS — it was not. The user caught and corrected this.
This harness file exists to prevent that mistake from recurring.

## Project rein roster

- `sync-debugger` — bidirectional local↔cloud sync specialist (outbox/inbox, anti-loop, origin_node_id, both-side DB inspection, cloud API probing)
- `laravel-reviewer` — Laravel/PHP code review (tenancy safety, Spatie teams, sync event emission, pre-push test gate)
- `phpunit-runner` — runs the ~390-test PHPUnit suite on Windows + Laragon + PHP 8.4, triages failures, owns the pre-push gate

## Hard rules for any worker in this harness

- Read `docs/` before guessing project conventions (especially `ENTORNO_VPS_POSTGRES_LOCAL_2026-07-05.md`,
  `DOMINIO_APP_MIINVENTARIOFACIL_VPS_2026-07-07.md`, and `API_NUBE_PERMANENTE_Y_PRUEBA_DOMINIO_2026-07-07.md`).
- **ALWAYS confirm VPS = 217.216.80.158 and DB = inventory_arens before touching the cloud.**
  Never trust memory or `~/.mavis/agents/<other-agent>/agent.md` for project identification.
- Verify BOTH sides of the sync (local DB + cloud DB on the right VPS) before declaring a sync fix complete.
- The cloud DB is on the host PostgreSQL (`127.0.0.1:5432`), NOT in a Docker container. Use
  `ssh webadmin@217.216.80.158 "sudo -u postgres psql -d inventory_arens -c '...'"` (no `docker exec`).
- Never push to the cloud without running the local pre-push suite (`bin/pre-push.php` → 0 failures).
- Never edit a single-line minified bundle in `frontend_web/dist/` directly — edit the source and rebuild.
- The pre-push gate is the law. If it fails, the push does not happen.
