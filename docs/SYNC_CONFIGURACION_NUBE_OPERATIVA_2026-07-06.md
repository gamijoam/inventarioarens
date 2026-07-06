# Configuracion operativa de sincronizacion nube

Fecha: 2026-07-06

## Objetivo

Conectar una instalacion local del sistema de inventario con una API Laravel en la nube para que pueda:

- subir eventos locales de ventas, inventario, precios y caja;
- bajar cambios hechos desde la nube;
- marcar una empresa como lista por computadora;
- mantener separado el trabajo por empresa, usuario y nodo local.

## Punto importante

La sincronizacion no debe operar directo contra PostgreSQL remoto.

PostgreSQL remoto se usa para revisar datos o para la API nube, pero el worker local debe hablar con Laravel por HTTP:

```text
Local -> API Laravel nube -> PostgreSQL nube
```

Esto mantiene validaciones, token, tenant, permisos, idempotencia y auditoria.

## Situacion detectada

El VPS responde en HTTPS con una aplicacion Express existente.

Por eso `/api/sync/status` devuelve `Cannot GET /api/sync/status`: esa ruta no pertenece a Laravel.

Para no romper la app existente, la primera version de la API nube del inventario se prepara en un puerto propio:

```text
http://217.216.80.158:8010/api
```

Mas adelante se puede publicar con dominio y certificado propio, por ejemplo:

```text
https://api.inventario.com/api
```

## Script para preparar el VPS

Archivo:

```text
scripts/cloud-api-bootstrap-vps.sh
```

Se ejecuta dentro del VPS, como root, despues de copiar o clonar el proyecto en:

```text
/opt/inventarioarens-cloud
```

El script:

- valida que exista `artisan`;
- crea la base `inventory_arens` si falta;
- prepara `.env` de produccion;
- ejecuta `composer install`;
- ejecuta migraciones y seeders base;
- opcionalmente carga empresas demo para pruebas;
- crea un servicio systemd llamado `inventarioarens-cloud-api`;
- levanta Laravel en `0.0.0.0:8010`;
- abre el puerto 8010 si `ufw` esta disponible.

Comando en el VPS:

```bash
cd /opt/inventarioarens-cloud
DB_PASSWORD='CLAVE_POSTGRES' bash scripts/cloud-api-bootstrap-vps.sh
```

Si la nube es de pruebas y necesitas las empresas demo (`demo-caracas`, `demo-valencia` y empresas multiempresa), ejecuta:

```bash
cd /opt/inventarioarens-cloud
CLOUD_SEED_DEMO=1 DB_PASSWORD='CLAVE_POSTGRES' bash scripts/cloud-api-bootstrap-vps.sh
```

Si el VPS ya esta instalado y solo falta cargar los datos demo, ejecuta:

```bash
cd /opt/inventarioarens-cloud
php artisan db:seed --class=DemoDataSeeder --force
php artisan db:seed --class=MultiCompanyLoginDemoSeeder --force
```

## Emitir token de sincronizacion

Archivo:

```text
app/Modules/Sync/Commands/IssueSyncTokenCommand.php
```

Comando en el VPS:

```bash
php artisan sync:issue-token demo-valencia gerente.valencia@demo.test --name=worker-valencia --days=365
```

Para las empresas multiempresa del demo, usa el slug exacto de la empresa:

```bash
php artisan sync:issue-token demo-valencia-centro gerente.valencia@demo.test --name=worker-valencia-centro --days=365
php artisan sync:issue-token demo-valencia-norte gerente.valencia@demo.test --name=worker-valencia-norte --days=365
```

El token se muestra una sola vez. Ese valor se configura en la computadora local.

Si el comando responde `Empresa no encontrada`, significa que ese `slug` no existe en la base de nube. En ese caso primero carga el seeder demo o revisa los slugs existentes en PostgreSQL.

## Configurar la PC local

Archivo:

```text
scripts/configure-sync-cloud-local.ps1
```

Comando en Windows:

```powershell
.\scripts\configure-sync-cloud-local.ps1 `
  -CloudUrl "http://217.216.80.158:8010/api" `
  -Token "TOKEN_GENERADO_EN_LA_NUBE" `
  -TenantSlug "demo-valencia" `
  -RunOnce
```

El script local:

- guarda `SYNC_CLOUD_URL` en `.env`;
- guarda `SYNC_CLOUD_TOKEN` en `.env`;
- limpia cache de Laravel local;
- opcionalmente ejecuta un ciclo manual del worker.

## Validacion esperada

Al ejecutar el ciclo manual:

```text
Sincronizacion ejecutada.
Eventos subidos: ...
Eventos bajados: ...
Eventos aplicados: ...
Fallos: 0
```

En WPF:

- el semaforo debe cambiar de `Pendiente` a `Sincronizado`;
- la ventana de progreso solo dira `Sincronizacion completada` cuando `sync/local-readiness` quede listo;
- si falta URL, token o API nube, mostrara `Sincronizacion pendiente` con la causa.

## Seguridad

Esta fase usa puerto directo para pruebas operativas.

Antes de produccion final se recomienda:

- usar dominio propio;
- activar HTTPS valido;
- no exponer PostgreSQL salvo para administracion puntual;
- rotar tokens de worker por empresa/sede;
- limitar origen por firewall cuando ya se conozcan las IP operativas.
