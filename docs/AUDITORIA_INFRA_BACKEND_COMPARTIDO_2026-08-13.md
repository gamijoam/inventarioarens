# Auditoria y plan: backend compartido para los 3 clientes Electron

> **Actualizacion del 2026-08-14:** las secciones de Fase 2 reflejan una implementacion historica que
> termino usando tareas programadas bajo `SYSTEM`. La incidencia real demostro que no se recupera
> cuando el backend termina y que los tres instaladores compiten por el mismo runtime. El plan
> canonico que sustituye esa fase es
> [`MOTOR_LOCAL_WINDOWS_PLAN_2026-08-14.md`](MOTOR_LOCAL_WINDOWS_PLAN_2026-08-14.md).

Fecha: 2026-08-13 · Estado: PLAN APROBADO — pendiente de implementacion

## 1. Resumen del problema (sintomas reportados)

El usuario reporta:

1. **Workers de sincronizacion "detenidos"** cada vez que actualiza una app.
2. **Error al pulsar "Sincronizar"** en el Soporte Tecnico, que lo envia a una pagina
   que "no deja acceder nada".
3. **Tras actualizar, los workers parecen no haber sincronizado nunca** ("como si nunca
   se hubieran sincronizado").
4. **Consola negra** del agente de impresion (ya corregida en 0.2.48: ahora usa VBS oculto).

## 2. Auditoria: hallazgos confirmados con datos reales

### 2.1 Arquitectura actual (por app Electron)

Cada cliente Electron (Administrativo, POS, Soporte Tecnico) **empaqueta su propio
backend Laravel** y, al abrir, intenta levantar:

- `php artisan serve` en el puerto **8787** (constante `API_PORT` en `backend-runtime.cjs`).
- `php artisan printer:serve` en el puerto **17777**.
- Comparten la carpeta de datos `%APPDATA%\InventarioArens` (`localDataRoot()`).

```txt
App Electron (Administrativo / POS / Soporte)
  └─ main.cjs
      ├─ localDataRoot()  = appData\InventarioArens   (COMPARTIDA, BD real de ~39MB)
      ├─ app.setPath('userData') = appData\InventarioArens-{Modo}  (vacias, solo Electron)
      └─ createLocalRuntime() -> createRuntimeSupervisor()
          ├─ levanta PHP:  artisan serve  :8787     <- MISMO PUERTO en las 3 apps
          ├─ levanta agente: printer:serve :17777
          └─ lock: dataRoot\.runtime-supervisor.lock (guardado por PID del proceso)
```

### 2.2 Evidencia

| # | Hallazgo | Evidencia |
|---|---|---|
| 1 | Las 3 apps usan el MISMO puerto `8787` y el MISMO `dataRoot` | `API_PORT = 8787` fijo; `localDataRoot()` identico en las 3. |
| 2 | Solo UNA app levanta el backend a la vez (lock compartido por PID) | `tryAcquireRuntimeSupervisorLock()`: si el lock de la app A esta vivo, la app B **no levanta su backend** y usa el de A. |
| 3 | "Workers detenidos" = la app B no pudo re-registrar tareas | El supervisor de B devuelve `false` en el lock y **nunca ejecuta** `local:repair-tasks`; las tareas de Windows quedan apuntando a rutas de apps viejas. |
| 4 | "Me envia a una pagina" = frontend de B contra la API vieja de A | El Soporte apunta a `:8787` que sirve la API del POS viejo (backend/BD/version distintas). |
| 5 | La BD buena (5 tenants, 19960 inbox, 97 outbox) esta INTACTA | `%APPDATA%\InventarioArens\inventario.sqlite` (39MB); `php artisan sync:run oscar-cell` funciona (bajo 10, aplico 1, 0 fallos). |
| 6 | Los datos NO se perdieron | `sync-config.json` con 5 tokens validos (oscar-cell, yaracall, chichiriviche, tucacas-grande, tucacas-peque) + BD completa. |

### 2.3 Causa raiz

El diseno asume que **una sola app corre el backend** (lock compartido por carpeta de
datos), pero las 3 apps compiten por el mismo puerto `8787` y la misma carpeta. Cuando se
abren 2+ apps (ej. Soporte + POS), la segunda **usa el backend de la primera** — que puede
ser de **una version distinta** — generando:

- Version inconsistente del backend entre apps.
- Workers/sync rotos (las tareas apuntan a rutas de apps viejas).
- Errores del frontend del Soporte contra una API ajena.
- Confusion sobre que BD usa cada app.

### 2.4 Estado de los datos (NO hay perdida)

- BD: `%APPDATA%\InventarioArens\inventario.sqlite` (39MB, completa).
- Tokens de sync: 5 empresas validas en `sync-config.json`.
- `sync:run` manual: **funciona** (0 fallos).
- La consola negra del agente de impresion: **ya corregida** en 0.2.48 (VBS oculto).

## 3. Solucion propuesta (la que pidio el usuario)

**Un solo backend compartido como servicio, al que las 3 apps se conectan.**

La intuicion del usuario es correcta: elimina el conflicto de puerto, el lock por PID,
las versiones distintas entre apps y el problema de workers al actualizar.

### 3.1 Principio de diseno

- **Backend unico** en `127.0.0.1:8787` (PHP Laravel + agente `printer:serve` en `:17777`).
- **Las 3 apps Electron son solo clientes**: se conectan a `http://127.0.0.1:8787`,
  NO empaquetan ni levantan su propio backend.
- **Una sola actualizacion del backend** (al actualizar cualquiera de las apps, o un
  servicio Windows dedicado) repara todas las tareas y workers.
- **Los datos** siguen en `%APPDATA%\InventarioArens` (sin cambios de ruta).

## 4. Plan de implementacion en 2 fases

### FASE 1 — Backend compartido con lock por version (fix rapido, low-risk)

Objetivo: que las apps **dejen de pelearse** y que los workers se reparen siempre,
sin re-arquitectura total.

#### 4.1 Cambios en `frontend/electron/backend-runtime.cjs`

1. **Backend por version**:
   - Escribir un archivo de version del backend en `dataRoot` (ej. `backend.version`)
     cuando el supervisor levanta la API.
   - Al arrancar, el supervisor lee esa version. Si la API responde pero es de una
     version **menor** que la de la app actual, **reemplaza el backend** (mata el viejo
     y levanta el suyo) aunque el lock este vivo (considera el lock stale si la version
     es menor).
   - Asi, la app mas nueva siempre impone su backend.

2. **`local:repair-tasks` corre SIEMPRE**:
   - Extraer el repair fuera del bloque condicionado por el lock. Aunque la app no sea
     la "duena" del backend, ejecuta `php artisan local:repair-tasks --printer` contra
     el backend compartido para re-registrar tareas con las rutas actuales.

3. **Health + version en `/up`**:
   - Agregar version del backend al endpoint `/up` (o `/api/health`) para que el
     supervisor decida si debe reemplazarlo.

#### 4.2 Cambios en el backend Laravel

1. **`local:repair-tasks` idempotente** (ya existe en 0.2.48): re-registra tareas de
   sync de los tenants con token + agente de impresion oculto. Se mantiene.

2. **Endpoint `/api/local-support/repair-tasks`** (opcional, para invocacion remota
   desde el frontend si hace falta).

#### 4.3 Criterios de exito (Fase 1)

- Abrir Administrativo + POS + Soporte a la vez: **todos usan el mismo backend** y ven
  los mismos datos.
- Tras actualizar una app, los workers **no quedan detenidos** (repair corre siempre).
- `Sincronizar` en el Soporte funciona (sin redireccion a pagina de error).
- La BD real no se toca.

### FASE 2 — Servicio Windows dedicado (la solucion robusta que propone el usuario)

Objetivo: un **servicio de Windows** que siempre levanta el backend, independiente de
las apps.

#### 4.4 Instalacion del servicio

- Instalar el backend como servicio Windows con **NSSM** (o `sc create`):
  ```txt
  NSSM  Instalar "InventarioArensBackend"
        Application: <php.exe>  artisan serve --host=127.0.0.1 --port=8787
        AppDirectory: <backend>
        Start: Automatic
  NSSM  Instalar "InventarioArensPrinter"
        Application: <php.exe>  artisan printer:serve --port=17777
  ```
- Configurar las tareas de sync (una por empresa) apuntando al mismo backend.

#### 4.5 Cambios en Electron

- Las 3 apps **ya no empaquetan** el backend (reducen tamano, sin conflictos).
- `createLocalRuntime` solo verifica que `:8787` responda; si no, muestra mensaje
  "Inicia el servicio InventarioArensBackend" (o lo inicia via `sc start`).

#### 4.6 Actualizacion del backend

- El release de la app actualiza el backend del servicio **una sola vez** (script del
  instalador NSIS que para el servicio, copia el nuevo backend, lo arranca).

#### 4.7 Riesgos Fase 2

- Requiere permisos de administrador para crear servicios.
- Migracion: las apps instaladas deben dejar de empaquetar el backend (cambio de
  tamano del instalador, requiere prueba en CI).
- NSIS custom action para instalar/actualizar el servicio.

## 5. Orden de ejecucion recomendado

1. [ ] Escribir tests del lock por version + repair siempre (Fase 1, TDD).
2. [ ] Implementar Fase 1 en `backend-runtime.cjs`.
3. [ ] Publicar Fase 1 (release 0.2.49) y validar en la PC local del usuario.
4. [ ] Si el usuario confirma, disenar Fase 2 (servicio Windows) como proyecto aparte.

## 6. Riesgos y mitigaciones

| Riesgo | Mitigacion |
|---|---|
| Reemplazar el backend en caliente puede cortar sync en curso | El reemplazo espera a que `requestHealth` falle o detecte version menor, y el `sync:run` es idempotente (event_uuid). |
| Version del backend inconsistente si no se versiona bien | Escribir `backend.version` al levantar y leerlo en cada arranque. |
| Fase 2 requiere admin | El instalador NSIS corre como admin; el servicio se registra con permisos elevados. |
| Perdida de datos | NO se tocan las rutas; la BD y tokens siguen en `%APPDATA%\InventarioArens`. |

## 7. Archivos afectados

| Archivo | Cambio |
|---|---|
| `frontend/electron/backend-runtime.cjs` | Lock por version, repair siempre, health con version. |
| `frontend/electron/backend-runtime.test.js` | Tests del lock por version + repair siempre. |
| `frontend/electron/main.cjs` | (Fase 2) dejar de empaquetar backend / conectar a servicio. |
| `app/Console/Commands/RepairLocalTasksCommand.php` | Ya existe (0.2.48); se mantiene. |
| `app/Modules/LocalSupport/...` | Ya soporta repair; se mantiene. |
| `docs/GUIA_PRUEBA_IMPRESORA_REAL.md` | Documentar el backend compartido. |
| `AGENTS.md` | Actualizar seccion Electron/infra con la arquitectura nueva. |

## 8. Estado actual

- [x] Auditoria completada (2026-08-13).
- [x] Consola negra del agente corregida (0.2.48, VBS oculto).
- [x] Fase 1: lock por version + repair siempre (implementada en 0.2.49).
      - `backend.version` en dataRoot; `isBackendOutdated` compara semver.
      - El supervisor toma control (takeover) si el backend es mas viejo.
      - Como cliente, ejecuta `local:repair-tasks` siempre que la API este arriba.
      - Tests: backend-runtime 20/20, tsc limpio, suite electron 54/54.
- [x] Fase 2: servicio Windows dedicado (implementacion incremental en release 0.2.50).

## 9. Que se implemento en la Fase 1 (commit d205932, release 0.2.49)

### 9.1 `frontend/electron/backend-runtime.cjs`

- **Nuevas funciones de version del backend**:
  - `backendVersionPath(config)` -> `dataRoot/backend.version`.
  - `readBackendVersion(config)` / `writeBackendVersion(config, version)`: persisten la
    version del backend que quedo a cargo.
  - `isBackendOutdated(runningVersion, ownVersion)`: compara semver (x.y.z). Devuelve
    `true` solo si la version propia es mayor; una version vacia nunca se considera
    outdated.
- **`resolveRuntimeConfig`** ahora incluye `appVersion` (de `options.appVersion`,
  `INVENTARIO_APP_VERSION` o `options.version`, default `0.0.0`).
- **`createRuntimeSupervisor.run()`** reescrito con 2 caminos:
  1. **Cliente** (backend ajeno ya corriendo y al dia): NO toma el lock, ejecuta
     `runRepairIfPossible` (repair de tareas) + `ensurePrinterAgent` + espera leases.
  2. **Owner / takeover**: si el backend esta desactualizado (`needsTakeover`), libera el
     lock, mata el proceso del puerto (`killProcessOnApiPort`) y levanta el suyo.
     Luego escribe `backend.version` y ejecuta `local:repair-tasks --printer`.
- **Nuevos helpers**:
  - `runRepairIfPossible(config, spawnProcess)`: corre `local:repair-tasks --printer`
    si existe `backendRoot/artisan`.
  - `killProcessOnApiPort(port)`: mata el proceso que escucha en el puerto (netstat en
    Windows, lsof en Linux) para el takeover.

### 9.2 `frontend/electron/main.cjs`

- `prepareServices()` pasa `appVersion: app.getVersion()` al `resolveRuntimeConfig`.

### 9.3 `frontend/electron/backend-runtime.cjs` (spawn del supervisor)

- `spawnRuntimeSupervisor` propaga `INVENTARIO_APP_VERSION` para que el proceso hijo del
  supervisor conozca la version de la app.

### 9.4 Tests (`frontend/electron/backend-runtime.test.js`)

- `persists and reads the backend version in the shared data root`.
- `detects an outdated running backend when own version is newer`.
- `releases a stale supervisor lock when a newer backend must take over`.
- `repairs windows tasks even when a compatible backend is already running`.
- `skips repair when the backend artisan is missing`.
- El archivo contiene 27 casos de runtime; incluye cobertura de deteccion, arranque y migracion
  del servicio dedicado. La ejecucion debe confirmarse en un entorno con Vitest operativo.

### 9.5 Estado de la Fase 2

La implementacion incremental del servicio Windows esta documentada en
`docs/FASE2_SERVICIO_WINDOWS_2026-08-13.md`. El instalador 0.2.50 registra el backend y el
agente de impresion como servicios nativos, conserva la BD y los tokens existentes, y hace que
Electron se conecte al backend compartido sin levantar otro proceso.

## 10. Fase 2 implementada — backend como servicio Windows dedicado

La implementacion descrita en esta seccion queda operativa desde el release `0.2.50`. El
procedimiento reproducible, las rutas y la reparacion de instalaciones existentes estan en
`docs/FASE2_SERVICIO_WINDOWS_2026-08-13.md`.

### 10.1 Objetivo

Que el backend Laravel + agente `printer:serve` corran como **un servicio de Windows
permanente**, independiente de las 3 apps Electron. Las apps dejan de empaquetar y
levantar su propio backend: solo se conectan a `http://127.0.0.1:8787`.

### 10.2 Implementacion operativa

1. **Servicios nativos de Windows**:
   - `scripts/install-backend-service.ps1` usa `New-Service` y `sc.exe`.
   - Registra `InventarioArensBackend` en `127.0.0.1:8787` y
     `InventarioArensPrinter` en `127.0.0.1:17777`.
   - Configura inicio automatico y `FailureActions` para reinicios progresivos.
   - Requiere permisos de administrador.

2. **Script de instalacion del servicio** (`scripts/install-backend-service.ps1`):
   - Detiene servicios anteriores, copia backend/PHP a `ProgramData`, aplica migraciones SQLite y
     vuelve a registrar/arrancar ambos servicios.

3. **Runtime Electron**:
   - En modo servicio no levanta `artisan serve` ni `printer:serve` desde Electron.
   - Ejecuta `sc start` para ambos servicios incluso si la API ya responde.
   - Verifica la API en `:8787` y ejecuta `local:repair-tasks` contra el backend compartido.

4. **Instalador NSIS** (`electron-builder.*.yml` + `.nsh`):
   - Los tres clientes instalan en carpetas separadas y registran el servicio compartido.
   - `customInstall` aborta si falla `install-backend-service.ps1`.
   - Los uninstallers individuales no eliminan los servicios compartidos.

5. **Actualizacion y migracion**:
   - Una instalacion nueva actualiza el backend una sola vez para los tres clientes.
   - El `app.key` existente se conserva tanto si ya contiene `base64:` como si fue creado en
     formato crudo.
   - La BD, tokens y storage permanecen en `%APPDATA%\InventarioArens`.

6. **Reparacion manual**:
   - Usa el mismo script para copiar el runtime y recrear servicios sin borrar la informacion local.

### 10.3 Riesgos y consideraciones Fase 2

| Riesgo | Mitigacion |
|---|---|
| Requiere admin para crear servicios | El instalador NSIS corre como admin; `sc start` con elevacion. |
| Cambio de tamano del instalador | Menos peso (sin backend/php empaquetado); validar en CI. |
| Compatibilidad con la BD actual | NO se toca `%APPDATA%\InventarioArens`; el servicio usa la misma BD. |
| Reinicio tras actualizar | `FailureActions` de `sc.exe` reinicia el proceso automaticamente. |
| Falla del servicio sin mensaje claro | Logs en `ProgramData` + health check en el arranque de cada app. |

### 10.4 Criterios de exito (Fase 2)

- Al iniciar Windows, el backend y el agente estan arriba **sin abrir ninguna app**.
- Las 3 apps se conectan a `:8787` y nunca compiten por el puerto.
- Actualizar cualquier app actualiza el servicio una sola vez (workers nunca rotos).
- El Soporte Tecnico sincroniza sin errores de pagina.
