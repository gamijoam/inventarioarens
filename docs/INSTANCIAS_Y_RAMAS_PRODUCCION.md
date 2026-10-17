# Manual de Operación: Instancias en Producción y Ramas Git

> Guía oficial y permanente sobre las 4 instancias activas en el servidor VPS, sus ramas en GitHub, puertos, bases de datos y flujos de despliegue.

---

## 1. Mapa de las 4 Instancias en Producción

El servidor VPS Contabo (`212.28.176.157`) aloja 4 instalaciones independientes del stack Laravel 13 + React SPA. Cada una cuenta con su propio subdominio público, base de datos PostgreSQL nativa, socket PHP-FPM 8.4 y puerto Nginx detrás de Traefik (Docker):

| Instancia | Dominio Público | Puerto Nginx | Base de Datos PostgreSQL | Rama en GitHub y Local | Propósito del Negocio |
|---|---|---|---|---|---|
| **BalanzaPro** | `app.balanzapro.com` | `172.18.0.1:8086` | `inventory_balanzapro` | `client/balanzapro` | Sistema POS y administrativo enfocado en pesaje comercial con balanzas HID, generación de instalador `.exe` todo-en-uno y catálogo con precios flexibles. |
| **Repuestos Avilacar** | `app.repuestosavilacar.com` | `172.18.0.1:8084` | `inventory_repuestosavilacar` | `client/repuestosavilacar` | Comercio de autopartes y repuestos automotrices. POS adaptado, buscador multitoken por repuesto y validación estricta de pagos. |
| **Tiendas Arens** | `app.tiendasarens.com` | `172.18.0.1:8082` | `inventory_tiendasarens` | `client/tiendasarens` | Tiendas de ropa y retail comercial multi-sucursal. |
| **Inventario Arens (Master)** | `app.miinventariofacil.com` | `172.18.0.1:8080` | `inventory_arens` | `main` | SaaS Master y núcleo base compartido del sistema. Sirve como base canónica para nuevas características y spinoffs. |

---

## 2. Descripción Detallada de Cada Instancia

### 2.1 BalanzaPro (`/opt/balanzapro-cloud`)
- **Rama:** `client/balanzapro`
- **Público objetivo:** Fruterías, carnicerías, charcuterías, bodegones y comercios que operan con balanzas electrónicas en mostrador.
- **Características exclusivas:**
  - Integración nativa con balanzas electrónicas por puerto USB/HID (`scale-agent.exe` en Go).
  - Precios de venta configurables por debajo del costo (eliminación de restricción rígida).
  - Catálogo optimizado con imágenes ampliadas y botón de eliminación rápido en cabecera.
  - Generador de instalador Windows Todo-en-Uno (`BalanzaPro-Setup.exe`) descargable desde la sección de Configuración web.

### 2.2 Repuestos Avilacar (`/opt/repuestosavilacar-cloud`)
- **Rama:** `client/repuestosavilacar`
- **Público objetivo:** Tiendas de repuestos, autopartes y suministros industriales.
- **Características exclusivas:**
  - Buscador inteligente en ventas que permite filtrar por múltiples palabras y códigos simultáneamente.
  - Ficha de punto de venta protegida: bloqueo de selección de métodos de pago cuando no hay artículos en el carrito y rediseño de eliminación clara de montos.
  - Manejo de inventario por ubicación física de anaqueles.

### 2.3 Tiendas Arens (`/opt/tiendasarens-cloud`)
- **Rama:** `client/tiendasarens`
- **Público objetivo:** Tiendas por departamento, calzado y textiles.
- **Características:**
  - Control de variantes de productos (talla, color, modelo).
  - Traslados interempresa y notificaciones multi-sucursal.
  - Manejo de reportes gerenciales v2 y arqueo de caja con reporte Z.

### 2.4 Inventario Arens - SaaS Master (`/opt/inventarioarens-cloud`)
- **Rama:** `main`
- **Propósito:**
  - Repositorio matriz donde reside la carpeta de compilación principal (`node_modules`).
  - Panel SaaS Master para administración de grupos empresariales, spinoffs y membresías cross-tenant.
  - Punto de partida limpio para futuras réplicas o clientes nuevos.

---

## 3. ¿Cómo se Manejan y Despliegan?

### 3.1 Vía GitHub Actions (Recomendado)

En la pestaña **Actions** de GitHub (`https://github.com/gamijoam/inventarioarens/actions`) tienes 3 flujos automatizados:

1. **Despliegue Manual por Instancia (`deploy-instance.yml`):**
   - Eliges la rama base (`client/balanzapro`, `client/repuestosavilacar`, `client/tiendasarens`, `main`).
   - Eliges la instancia destino (`balanzapro`, `repuestosavilacar`, `tiendasarens`, `inventarioarens` o `all`).
   - Compila el frontend en los runners de GitHub y se conecta de forma segura vía SSH para actualizar la instancia sin downtime.

2. **Despliegue Automático por Pull Request (`deploy-pr.yml`):**
   - Al crear una PR contra una rama, se compila y despliega automáticamente a su instancia:
     - PR hacia `client/balanzapro` → Despliega en **BalanzaPro**.
     - PR hacia `client/repuestosavilacar` → Despliega en **Avilacar**.
     - PR hacia `client/tiendasarens` → Despliega en **Tiendas Arens**.
     - PR hacia `main` → Despliega en **InventarioArens (Master)**.

3. **Generar Instalador Windows .EXE (`build-installer.yml`):**
   - Permite generar bajo demanda el paquete instalador completo (`BalanzaPro-Setup.exe`) y su manifiesto (`BalanzaPro-Setup.manifest.json`).
   - Lo publica automáticamente en la carpeta `/public/downloads/` de la instancia elegida para descarga inmediata desde la interfaz web.

---

### 3.2 Vía Consola en el Servidor VPS (CLI)

Si estás conectado por SSH al VPS, cuentas con scripts optimizados:

#### Desplegar cualquier instancia:
```bash
# Desplegar BalanzaPro con la última versión de su rama:
bash /opt/inventarioarens-cloud/scripts/deploy-target-instance.sh balanzapro client/balanzapro

# Desplegar Avilacar:
bash /opt/inventarioarens-cloud/scripts/deploy-target-instance.sh repuestosavilacar client/repuestosavilacar

# Opciones adicionales:
#   --skip-migrations   (no corre php artisan migrate)
#   --skip-composer     (no corre composer install)
#   --clean-checkout    (fuerza reset limpio)
```

#### Compilar y publicar el instalador .EXE:
```bash
bash /opt/balanzapro-cloud/scripts/build-installer-direct.sh --version=1.0.0 --target=balanzapro --publish
```

---

## 4. Reglas Críticas de Convivencia en el VPS

1. **No tocar Docker ajeno:** El VPS aloja 27 contenedores Docker de otros productos (FastAPI, Redis, MiInventario WhatsApp). INVENTARIOARENS corre en el stack nativo (PHP-FPM + Nginx en `172.18.0.1:*`).
2. **Cada instancia es independiente:** Las 4 instancias tienen bases de datos PostgreSQL separadas (`inventory_balanzapro`, `inventory_repuestosavilacar`, `inventory_tiendasarens`, `inventory_arens`). Nunca cruzar migraciones ni comandos con conexiones equivocadas.
3. **No romper ramas:** Cada instancia debe permanecer en su rama Git oficial (`client/*` o `main`).
