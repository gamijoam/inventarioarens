# Entornos Separados: Main y Programa Fiscal

## Objetivo

El desarrollo fiscal se ejecuta en un worktree y una base SQLite independientes. Esto permite
probar migraciones y cambios fiscales sin modificar el codigo ni los datos del entorno normal.

## Entornos

| Entorno | Worktree | Rama | Base SQLite |
|---|---|---|---|
| Normal | `INVENTARIOARENS/` | `main` | `storage/framework/local-dev.sqlite` |
| Fiscal | `INVENTARIOARENS-fiscal/` | `programa-fiscal` | `storage/framework/local-fiscal.sqlite` |

El worktree fiscal tiene un `.env` local, no versionado, con `DB_CONNECTION=sqlite` y
`DB_DATABASE=storage/framework/local-fiscal.sqlite`. El `.env` de `main` no se modifica.

Las bases no se comparten. Una migracion ejecutada en el worktree fiscal solo afecta a
`local-fiscal.sqlite`.

## Rama Fiscal

La rama `programa-fiscal` contiene:

- `9bc2af3`: auditoria fiscal/funcional POS, hardening de operaciones y roadmap.
- `85afb6e`: correcciones posteriores de la suite base y compatibilidad SQLite.
- Este documento y la configuracion de aislamiento del archivo SQLite.

El prompt original de auditoria se conserva en el worktree fiscal como archivo de trabajo y no se

## Copia y Restauracion SQLite

No copiar manualmente el archivo mientras SQLite este usando WAL. Usar el respaldo SQLite de PHP:

```bash
php -r '$source = new SQLite3("storage/framework/local-dev.sqlite", SQLITE3_OPEN_READONLY); $destination = new SQLite3("storage/framework/local-fiscal.sqlite"); if (!$source->backup($destination)) { exit(1); } $destination->close(); $source->close();'
```

Para respaldar antes de una migracion, usar otro nombre ignorado por Git:

```bash
php -r '$source = new SQLite3("storage/framework/local-fiscal.sqlite", SQLITE3_OPEN_READONLY); $destination = new SQLite3("storage/framework/local-fiscal-backup.sqlite"); if (!$source->backup($destination)) { exit(1); } $destination->close(); $source->close();'
```

Los archivos `local-fiscal*.sqlite`, incluidos sus archivos WAL/SHM, estan excluidos mediante
`storage/framework/.gitignore` y nunca deben subirse al repositorio.

## Flujo para Migraciones Fiscales

Trabajar desde el worktree fiscal:

```bash
cd /home/gamijoam/Documentos/INVENTARIOARENS-fiscal
php artisan optimize:clear
php artisan migrate
php vendor/bin/phpunit -c phpunit.sqlite.xml
vendor/bin/pint --test
```

Orden obligatorio:

1. Crear primero las pruebas de la migracion o funcionalidad.
2. Respaldar `local-fiscal.sqlite`.
3. Crear y ejecutar la migracion en `programa-fiscal`.
4. Ejecutar las pruebas afectadas y luego la suite SQLite completa.
5. Aplicar Pint y revisar el diff antes de hacer commit.

No ejecutar `php artisan migrate` desde `INVENTARIOARENS/` mientras se pretenda preservar el
entorno normal.

## Pruebas

`phpunit.sqlite.xml` utiliza `DB_DATABASE=:memory:`. Por eso sus pruebas no modifican ni
`local-dev.sqlite` ni `local-fiscal.sqlite`.

La base persistente fiscal se usa para probar migraciones, seeders y flujos manuales que requieran
datos conservados entre comandos.

## Documentacion de Auditoria e Implementacion

La rama fiscal conserva los documentos producidos durante la auditoria:

- `AUDITORIA_FISCAL_FUNCIONAL_POS_VENEZUELA_2026-08-16.md`
- `docs/GUIA_MEJORAS_POS_VENEZUELA.md`
- `docs/IMPLEMENTATION_LOG.md`
- `docs/SQLITE_LOCAL_ARCHITECTURE.md`

Tambien conserva los tests y cambios de implementacion del hardening POS, idempotencia,
concurrencia, creditos, sincronizacion y reservas.
