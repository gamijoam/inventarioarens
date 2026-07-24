# MCP ChatGPT Connector para INVENTARIOARENS

Este MCP permite conectar ChatGPT/Codex con el proyecto **INVENTARIOARENS** en el VPS.
Su alcance queda limitado a:

- Proyecto: `/opt/inventarioarens-cloud`
- Base de datos: `inventory_arens`
- Acciones: lectura de archivos, busqueda, escritura controlada de archivos del proyecto y consultas SQL de solo lectura.

No debe tocar Docker, Traefik, otros productos del VPS ni otras bases de datos.

## Campos para ChatGPT

En ChatGPT > Complementos > Nuevo complemento:

- Nombre: `InventarioArens MCP`
- Descripcion: `Herramienta tecnica para consultar y modificar el proyecto INVENTARIOARENS y diagnosticar su BD inventory_arens.`
- Conexion: `https://app.miinventariofacil.com/sse`
- Autenticacion: `Sin autenticacion` si ChatGPT muestra esa opcion.

El servidor exige una clave operativa por herramienta:

```text
access_key: <clave entregada al instalar el MCP>
```

Ejemplo de uso:

```text
Usa InventarioArens MCP con access_key <clave>. Revisa el estado del tenant demo-caracas.
```

## Herramientas expuestas

- `project_status`: estado del repo, commit actual y archivos modificados.
- `list_project_files`: lista archivos dentro del proyecto.
- `read_project_file`: lee archivos de texto del proyecto.
- `search_project`: busca texto dentro del proyecto con ripgrep.
- `write_project_file`: escribe archivos de texto con proteccion `expected_current`.
- `db_select`: ejecuta SQL de solo lectura (`SELECT` o `WITH`) sobre `inventory_arens`.
- `tenant_overview`: resumen operativo de un tenant.
- `search_products`: busca productos y stock.
- `sync_overview`: resumen de inbox/outbox de sincronizacion.

## Seguridad aplicada

- Todas las herramientas requieren `access_key`.
- Las rutas se resuelven siempre dentro de `/opt/inventarioarens-cloud`.
- Se bloquean `.git`, `vendor`, `node_modules` y caches de Laravel.
- No se permite escribir `.env`, `storage/`, `vendor/`, `node_modules/` ni `.git/`.
- `db_select` bloquea SQL que no sea `SELECT`/`WITH` y ejecuta la conexion en modo read-only.
- No existe herramienta de shell libre.

## Instalacion en VPS

Desde el VPS:

```bash
cd /opt/inventarioarens-cloud
python3 -m venv /opt/inventarioarens-mcp/.venv
/opt/inventarioarens-mcp/.venv/bin/pip install --upgrade pip
/opt/inventarioarens-mcp/.venv/bin/pip install "mcp" "psycopg[binary]"
```

Crear `/etc/inventarioarens-mcp.env`:

```env
INVENTARIOARENS_PROJECT_ROOT=/opt/inventarioarens-cloud
INVENTARIOARENS_MCP_ACCESS_KEY=<clave-larga>
INVENTARIOARENS_DB_HOST=127.0.0.1
INVENTARIOARENS_DB_PORT=5432
INVENTARIOARENS_DB_NAME=inventory_arens
INVENTARIOARENS_DB_USER=postgres
INVENTARIOARENS_DB_PASSWORD=<password-db>
INVENTARIOARENS_MCP_HOST=127.0.0.1
INVENTARIOARENS_MCP_PORT=17888
```

Crear servicio systemd `/etc/systemd/system/inventarioarens-mcp.service`:

```ini
[Unit]
Description=InventarioArens MCP Server
After=network.target postgresql.service

[Service]
Type=simple
WorkingDirectory=/opt/inventarioarens-cloud
EnvironmentFile=/etc/inventarioarens-mcp.env
ExecStart=/opt/inventarioarens-mcp/.venv/bin/python /opt/inventarioarens-cloud/tools/mcp/inventarioarens_mcp_server.py
Restart=always
RestartSec=5
User=www-data
Group=www-data

[Install]
WantedBy=multi-user.target
```

Habilitar:

```bash
systemctl daemon-reload
systemctl enable --now inventarioarens-mcp
systemctl status inventarioarens-mcp
```

## Nginx

El dominio `app.miinventariofacil.com` ya apunta al nginx nativo de INVENTARIOARENS.
Agregar en el server block de INVENTARIOARENS:

```nginx
location /sse {
    proxy_pass http://127.0.0.1:17888/sse;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 3600s;
}

location /messages/ {
    proxy_pass http://127.0.0.1:17888/messages/;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 3600s;
}
```

Luego:

```bash
nginx -t
systemctl reload nginx
```

## Operacion

```bash
systemctl status inventarioarens-mcp
journalctl -u inventarioarens-mcp -n 100 --no-pager
systemctl restart inventarioarens-mcp
systemctl stop inventarioarens-mcp
```

## Nota sobre OAuth

Esta v1 usa `access_key` por herramienta para mantener la instalacion simple. Si ChatGPT exige OAuth
obligatorio en tu cuenta, la v2 debe agregar endpoints OAuth (`authorize`, `token`, metadata) y
validacion Bearer antes de exponer `/sse`.
