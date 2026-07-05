# PostgreSQL local en VPS sin Docker

## Objetivo

Dejar preparado el VPS para usar PostgreSQL instalado directamente en Ubuntu, sin depender de contenedores Docker para la base principal del sistema.

## Revision realizada

Servidor correcto revisado por SSH manual:

- IP: `217.216.80.158`
- Host: `vmi3267687`
- Sistema operativo: Ubuntu 24.04.4 LTS

Nota: durante la revision inicial se consulto otro host (`vmi3062717`) mediante MCP. Ese servidor no corresponde al VPS operativo que el propietario usa por SSH manual. La referencia valida para este entorno queda establecida como `vmi3267687`.

Estado encontrado en el VPS correcto:

- PostgreSQL 16 ya estaba instalado en el host.
- El cluster local `16/main` estaba activo en el puerto `5432`.
- Solo existian las bases por defecto: `postgres`, `template0` y `template1`.

## Instalacion validada

PostgreSQL esta instalado directamente en el host con paquetes oficiales de Ubuntu:

- `postgresql`
- `postgresql-contrib`
- Version instalada: PostgreSQL 16

El servicio quedo habilitado para iniciar con el sistema:

- Servicio: `postgresql`
- Cluster: `16/main`
- Estado: `online`
- Puerto local: `5432`

## Bases creadas en el VPS correcto

Se crearon y validaron las bases iniciales:

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

Validacion ejecutada en el VPS:

```bash
sudo -u postgres psql -tAc "select datname from pg_database order by datname;"
PGPASSWORD='********' psql -h 127.0.0.1 -p 5432 -U postgres -d inventory_arens -tAc "select current_database();"
```

Resultado esperado:

```text
inventory_arens
inventory_arens_testing
postgres
template0
template1
```

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
