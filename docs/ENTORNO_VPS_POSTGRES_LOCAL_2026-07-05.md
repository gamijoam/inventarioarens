# PostgreSQL local en VPS sin Docker

## Objetivo

Dejar preparado el VPS para usar PostgreSQL instalado directamente en Ubuntu, sin depender de contenedores Docker para la base principal del sistema.

## Revision realizada

Servidor revisado:

- Host: `vmi3062717`
- Sistema operativo: Ubuntu 24.04.3 LTS

Antes de instalar:

- No existia binario `psql` en el host.
- No habia paquetes `postgresql` instalados en Ubuntu.
- No habia servicio `postgresql` registrado en `systemd`.
- El puerto visible relacionado con PostgreSQL era `54322`, expuesto por Docker.

## Instalacion realizada

Se instalo PostgreSQL directamente en el host con paquetes oficiales de Ubuntu:

- `postgresql`
- `postgresql-contrib`
- Version instalada: PostgreSQL 16

El servicio quedo habilitado para iniciar con el sistema:

- Servicio: `postgresql`
- Cluster: `16/main`
- Estado: `online`
- Puerto local: `5432`

## Bases creadas

Se crearon las bases iniciales:

- `inventory_arens`
- `inventory_arens_testing`

## Conexion

El servicio escucha solo localmente:

- `127.0.0.1:5432`
- `[::1]:5432`

Esto evita exponer PostgreSQL directamente a internet.

Usuario configurado:

- `postgres`

La clave fue definida segun lo indicado por el propietario del servidor. No se documenta la clave en archivos del proyecto.

## Docker existente

Todavia existen contenedores PostgreSQL en Docker:

- `db_qa_server`
- `db_prod_server`

Esos contenedores siguen disponibles, pero la decision nueva para el entorno principal es trabajar con PostgreSQL local del VPS.

## Siguiente paso recomendado

Cuando se prepare el backend en el VPS sin Docker, se debe configurar el `.env` con:

- `DB_CONNECTION=pgsql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=5432`
- `DB_DATABASE=inventory_arens`
- `DB_USERNAME=postgres`

Luego se deben ejecutar migraciones y pruebas contra esta base local del VPS.

