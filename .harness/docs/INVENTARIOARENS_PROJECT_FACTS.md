# INVENTARIOARENS — proyecto real del usuario

## Datos básicos

- **VPS**: `217.216.80.158` (host: `vmi3267687`, Ubuntu 24.04.4 LTS, Contabo)
- **SSH key**: `C:\Users\gafit\.ssh\webadmin-vps` (user `webadmin`)
- **DB local en Windows**: PostgreSQL 16 en `127.0.0.1:5434`, DB `inventory_arens`, user/pass `inventory_arens`/`secret`
- **DB en VPS (cloud backend)**: PostgreSQL 16 instalado directamente en el host (NO Docker) en `127.0.0.1:5432`, DB `inventory_arens`, user `postgres`
- **Cloud backend**: Laravel 13 servido por Nginx + PHP-FPM en `/opt/inventarioarens-cloud/public`
- **URL pública de la nube**: `https://app.miinventariofacil.com/api` (DNS A → 217.216.80.158, HTTPS con Let's Encrypt)
- **Stack local**: Windows + Laragon + PHP 8.4.23 (`C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe`)
- **Frontend**: Vite + React + Inertia (`frontend_web/`), admin panel Livewire/Tailwind

## ⚠️ NO CONFUNDIR con el otro proyecto del usuario

El usuario tiene DOS productos SaaS con la misma marca. Son DIFERENTES proyectos, en DIFERENTES VPS, con DIFERENTES stacks:

| Proyecto            | VPS              | Backend            | DBs            | SSH key             |
|--------------------|------------------|--------------------|----------------|---------------------|
| **INVENTARIOARENS** | **217.216.80.158**| **Laravel 13**     | **inventory_arens** | **webadmin-vps** |
| MiInventarioFácil  | 212.28.176.157   | FastAPI 2.2.0 (QA) | invensoft_qa / invensoft_prod | bloqueo_vps_mavis |

La sesión anterior (2026-07-10/11) confundió ambos proyectos. SSH a `212.28.176.157`, consultó
`invensoft_qa`, asumió que era INVENTARIOARENS. NO lo era. El usuario lo corrigió y pidió
grabar esta distinción en los archivos.

**Regla de oro**: antes de tocar la nube, confirmar SIEMPRE que es el VPS `217.216.80.158` y la DB `inventory_arens`.

## Para acceder al VPS desde Windows

```bash
ssh -i C:\Users\gafit\.ssh\webadmin-vps webadmin@217.216.80.158
```

## Para consultar la DB de la nube

```bash
ssh -i C:\Users\gafit\.ssh\webadmin-vps webadmin@217.216.80.158 "sudo -u postgres psql -d inventory_arens -c 'SELECT ...'"
```

## Para el sync desde local

`.env` local ya tiene:
- `SYNC_CLOUD_URL=https://app.miinventariofacil.com/api`
- `SYNC_CLOUD_TOKEN=kzunwmhpZft00WbST2BAuybCtRbHy0IQy8Ztn6peYEX65L2NjH9ApHAteMaNMrQzybXtMzhf5PqVkDSd`
