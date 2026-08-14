# Motor Local Windows — implementacion y piloto

Fecha: 2026-08-14  
Estado: implementado y validado en la computadora piloto  
Version piloto instalada: `0.1.2`

## Resultado

El backend local dejo de pertenecer a Administrativo, POS o Soporte Tecnico. Existe un instalador
independiente llamado **Motor Local - Sistema de Inventario** que administra tres servicios Windows:

- `SistemaInventarioBackend`: Laravel en `127.0.0.1:8787`.
- `SistemaInventarioPrinter`: agente de impresion en `127.0.0.1:17777`.
- `SistemaInventarioSync`: supervisor de sincronizacion de todas las empresas configuradas.

Los servicios usan WinSW 2.12.0 con inicio automatico retardado, reinicio ante fallo y logs
rotativos. El payload es inmutable y versionado bajo
`C:\Program Files\Sistema de Inventario\Motor\versions\<version>`.

La base, tokens, storage y logs permanecen en `C:\ProgramData\InventarioArens` durante esta primera
fase. El nombre legado de esa carpeta se conserva deliberadamente para evitar una migracion de datos
riesgosa; no aparece como nombre del producto instalado ni de los servicios nuevos.

## Seguridad y actualizaciones

- Cada actualizacion crea primero un respaldo de SQLite, incluido WAL/SHM cuando existen.
- Las migraciones se ejecutan antes de cambiar el marcador activo.
- Los servicios anteriores solo se retiran despues de aprobar los health checks.
- Un fallo inicia rollback de servicios y restaura la copia SQLite previa.
- `app.key` y `bootstrap.token` quedan restringidos a SYSTEM y Administradores. Los XML de WinSW
  contienen solo las rutas de esos archivos, nunca sus valores.
- Desinstalar el Motor conserva SQLite, tokens, storage, logs y respaldos.
- Desinstalar cualquiera de los tres clientes no toca el Motor.

## Sincronizacion sin tareas programadas

`php artisan sync:daemon-all` relee `storage/app/sync-worker/sync-config.json` en cada ciclo. Cada
empresa conserva su token, nodo e instalacion independientes. Un error de una empresa se registra y
no impide procesar las demas. El campo `interval` de cada empresa se respeta de forma independiente
entre `5` y `300` segundos; `--interval` solo funciona como fallback si una configuracion no lo
define. El supervisor duerme hasta el siguiente tenant pendiente, por lo que una empresa rapida no
queda bloqueada por otra con un intervalo mayor.

Al migrar, el instalador detiene las tareas `SistemaInventarioSync-*`, inicia y valida
`SistemaInventarioSync`, y solo entonces elimina las tareas antiguas. Los clientes Electron ya no
ejecutan `local:repair-tasks`.

Cuando `INVENTARIO_SERVICE_MODE=1`, Soporte Tecnico reporta el servicio central y rechaza las
acciones de instalar, iniciar, detener o reiniciar un worker por empresa. Las tareas antiguas solo
se conservan como ruta de migracion para instalaciones que aun no usan el Motor Local.

## Evidencia del piloto 0.1.2

- Instalacion y actualizaciones `0.1.0 -> 0.1.1 -> 0.1.2` conservaron SQLite y cinco configuraciones
  de empresa.
- Backend: HTTP 200 en `/up`.
- Impresion: HTTP 200 en `/health`.
- Sync: cinco empresas procesadas, cero fallos en los ciclos observados.
- Recuperacion: se termino deliberadamente el PID `12276`; WinSW creo el PID `19796` y `/up` volvio
  a responder HTTP 200 automaticamente.
- Artefacto piloto 0.1.2: 53.324.359 bytes; SHA-256
  `AB5390DE95A71E7C474D0E3C46A191E1F63FB9FBF9F4727684D97BC605C16A1B`.
- Despues del piloto se reforzo el rollback del tercer servicio y el modo `ValidateOnly`; el payload
  de fuente se valido como `0.1.3` sin instalar. El proximo release debe construirse desde el commit
  final con version `0.1.3` o superior; no publicar el ejecutable piloto 0.1.2 como release definitivo.

## Pruebas

- Contratos backend/sync/instalador afectados: 20 ejecutadas, 19 aprobadas y 1 omitida por plataforma.
- Contratos finales de secretos + supervisor: 3 aprobadas, 86 aserciones.
- Runtime Electron: 34 aprobadas.
- Suite frontend completa: 281 suites, 683 pruebas aprobadas, 0 fallidas.
- TypeScript, ESLint de archivos afectados, Pint y parser PowerShell: aprobados.
- Suite backend completa en SQLite: 1.294 ejecutadas; 1.279 aprobadas, 6 omitidas, 6 fallos y 3
  errores preexistentes/no relacionados. Los hallazgos incluyen pruebas PostgreSQL ejecutadas sobre
  SQLite, una constante retirada y fixtures antiguos. Ninguno pertenece al Motor ni a
  `sync:daemon-all`; las suites afectadas estan verdes.
- No existe runner de mutation testing adoptado en Composer ni frontend; queda como riesgo de
  infraestructura de pruebas, no como prueba ejecutada.

## Publicacion e instalacion limpia

1. Publicar primero el Motor con `.github/workflows/release-motor.yml`.
2. Instalar el Motor una sola vez como administrador y comprobar los tres servicios.
3. Instalar Administrativo, POS y Soporte Tecnico en cualquier orden. Son solo interfaces.
4. Las actualizaciones de interfaz usan `.github/workflows/release.yml` y no cambian el Motor.
5. Si cambia Laravel, PHP, sync o impresion, publicar una version independiente del Motor.

Los clientes deben abrir su ventana aunque el Motor este caido y mostrar un diagnostico visible. No
deben cerrarse silenciosamente ni solicitar privilegios cuando el backend ya esta sano.

## Build y release reproducible

El build oficial se ejecuta en `windows-latest` mediante `.github/workflows/release-motor.yml`; Linux
no necesita instalar PHP portable ni Inno Setup para generar el artefacto. Para una prueba de VM:

```bash
gh workflow run release-motor.yml -f version=0.1.3-test -f prerelease=true
gh release download motor-v0.1.3-test --pattern '*.exe' --pattern '*.sha256'
```

El workflow prepara PHP portable, WinSW y el payload, construye
`Motor-Local-Sistema-Inventario-<version>.exe`, publica su SHA-256 y lo marca como prerelease cuando
se solicita. Para construir localmente en Windows se puede usar:

```powershell
.\scripts\build-local-motor.ps1 -Version 0.1.3-test
```
