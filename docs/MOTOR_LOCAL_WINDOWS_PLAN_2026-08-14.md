# Motor Local de Windows — arquitectura y plan de estabilizacion

Fecha: 2026-08-14  
Estado: **arquitectura aprobada; implementacion piloto completada**
La evidencia operativa y los resultados del piloto estan en
`docs/MOTOR_LOCAL_WINDOWS_IMPLEMENTACION_2026-08-14.md`.

## 1. Decision

El backend Laravel, PHP portable, SQLite, el agente de impresion y sus procesos auxiliares se
separaran de los instaladores de Administrativo, POS y Soporte Tecnico.

Se creara un cuarto paquete independiente:

**Motor Local — Sistema de Inventario**

El Motor Local se instalara una sola vez con permisos de administrador. Los tres clientes Electron
seran consumidores del motor y no podran detenerlo, reemplazarlo, registrarlo ni desinstalarlo.

No se continuara extendiendo el modelo actual de tareas programadas como solucion permanente.

## 2. Incidente que origino esta decision

El 2026-08-14 Administrativo quedo congelado, mostro error de conexion y posteriormente dejo de
mostrar su ventana.

Evidencia observada en la PC:

- El renderer local alcanzaba a escuchar en `127.0.0.1:8788`.
- La API local no respondia en `127.0.0.1:8787`.
- Existian procesos Electron sin una ventana principal.
- `backend.log` terminaba con `^C`, consistente con una terminacion externa del proceso y no con
  una excepcion de Laravel o corrupcion de SQLite.
- La tarea se ejecutaba bajo `SYSTEM`; el usuario interactivo no podia consultarla ni iniciarla.
- La base `inventario.sqlite`, los tokens y el storage permanecieron intactos.
- La reparacion elevada recreo las tareas y restablecio los puertos `8787` y `17777`; Administrativo
  abrio nuevamente sin reconstruir la base.

El historial operativo del Programador de tareas estaba desactivado. Por ello no es posible atribuir
el `^C` a un ejecutable concreto. La causa estructural si queda demostrada: una vez detenido el
proceso, el diseño actual no podia recuperarlo desde una aplicacion ejecutada como usuario normal.

## 3. Causas raiz del diseño actual

### 3.1 Tareas `SYSTEM` que las aplicaciones no pueden iniciar

`InventarioArensBackend` e `InventarioArensPrinter` se registran con usuario `SYSTEM`, nivel
`Highest` y un unico trigger `AtStartup`.

Electron intenta ejecutar `schtasks.exe /Run /TN ...` sin elevacion. Windows responde `Access is
denied`. El runtime actual considera expresamente ese resultado como exito y luego espera una API
que nunca fue iniciada.

### 3.2 No existe recuperacion real

Las tareas no configuran `RestartCount`, `RestartInterval` ni un supervisor persistente. Si PHP
termina despues del arranque de Windows, no existe una autoridad confiable que lo reinicie.

### 3.3 La ventana se crea demasiado tarde

`main.cjs` espera que el backend este saludable antes de crear `BrowserWindow`. Cuando el backend
falla, el usuario no recibe una pantalla de diagnostico: la aplicacion permanece sin ventana y
finalmente termina.

### 3.4 Tres instaladores son propietarios del mismo recurso

Administrativo, POS y Soporte incluyen backend, PHP y el mismo script de instalacion. Cualquiera de
los tres puede:

1. detener los procesos de los puertos `8787` y `17777`;
2. eliminar las tareas compartidas;
3. reemplazar backend y PHP;
4. ejecutar migraciones;
5. volver a registrar las tareas.

La operacion no es transaccional. Un fallo entre los pasos 2 y 5 deja a todos los clientes sin
backend. Las actualizaciones independientes tambien pueden interrumpir otro cliente que siga abierto.

### 3.5 El launcher depende de una aplicacion

Aunque PHP se copia al directorio compartido, `backend.cmd` y `printer.cmd` se generan con la ruta
del PHP incluido en el cliente que ejecuto el instalador. Ejemplo observado:

```text
C:\Program Files\Sistema-de-Inventario-Administrativo\resources\runtime\php\php.exe
```

Desinstalar o reemplazar ese cliente puede invalidar el motor compartido. El launcher deberia usar
unicamente el runtime propiedad del Motor Local.

### 3.6 Los tests validan el comportamiento incorrecto

Existe una prueba que exige continuar cuando Windows responde `Access is denied`. Esa expectativa
oculta el fallo en vez de demostrar que el backend fue iniciado. Debe reemplazarse por contratos que
exijan estado saludable o un error visible y accionable.

## 4. Arquitectura objetivo

### 4.1 Responsabilidades

| Componente | Propietario | Responsabilidad |
|---|---|---|
| Motor Local | Instalador independiente elevado | Backend, PHP, migraciones, impresion, servicios y recuperacion |
| Administrativo | Auto-updater del canal `admin` | Interfaz administrativa; nunca modifica el motor |
| POS | Auto-updater del canal `pos` | Interfaz de venta; nunca modifica el motor |
| Soporte Tecnico | Auto-updater del canal `technician` | Vinculacion, diagnostico y solicitud explicita de actualizacion del motor |
| Workers por empresa | Motor/Soporte | Sincronizacion; no deben apuntar a carpetas de un cliente Electron |

### 4.2 Rutas canonicas propuestas

```text
C:\Program Files\Sistema de Inventario\Motor\
  current\backend\
  current\runtime\php\
  current\service\

C:\ProgramData\SistemaInventario\
  inventario.sqlite
  storage\
  logs\
  config\
  secrets\
  updates\
```

La ruta legado `C:\ProgramData\InventarioArens` se migrara una sola vez y no se eliminara hasta
validar base, tokens, archivos y sincronizacion. La migracion debe ser idempotente y con respaldo.

### 4.3 Servicios reales de Windows

Se usara un wrapper de servicio que implemente correctamente el protocolo del Service Control
Manager, preferiblemente **WinSW empaquetado dentro del Motor Local**. No se registrara un `.cmd` o
`php.exe` directamente con `sc create`.

Servicios previstos:

- `SistemaInventarioBackend`: API en `127.0.0.1:8787`.
- `SistemaInventarioPrinter`: agente en `127.0.0.1:17777`.
- `SistemaInventarioSync`: supervisor continuo de todas las empresas configuradas.

La sincronizacion deja de depender de una tarea programada por empresa. El servicio ejecuta
`php artisan sync:daemon-all`, relee `sync-config.json` en cada ciclo y aisla los fallos: una empresa
sin conexion no impide que las demas se sincronicen. Las tareas `SistemaInventarioSync-*` se eliminan
despues de que el servicio nuevo queda activo.

Configuracion minima:

- inicio automatico retrasado;
- cuenta `LocalSystem` o una cuenta de servicio con permisos minimos;
- reinicio tras fallo a los 5, 15 y 60 segundos;
- limite y rotacion de logs;
- dependencias y variables de entorno declaradas por el Motor;
- ejecutables apuntando solo a `Motor\current`;
- consulta de estado permitida a usuarios normales;
- cambios de configuracion reservados a administradores.

El uso definitivo y la licencia del wrapper deben validarse antes de incorporarlo al release.

## 5. Instalacion limpia futura

El orden soportado sera:

1. Ejecutar `Motor-Local-Sistema-Inventario-<version>.exe` como administrador.
2. El instalador detecta y respalda la instalacion legado.
3. Copia el motor a una ruta temporal/versionada.
4. Valida PHP, extensiones, certificados, Laravel y permisos.
5. Ejecuta la instalacion/migracion SQLite de forma idempotente.
6. Registra los servicios y espera health checks de `8787` y `17777`.
7. Solo despues de ambos health checks marca la instalacion como activa.
8. Instalar Administrativo, POS y Soporte en cualquier orden.
9. Vincular las empresas desde Soporte Tecnico y verificar los workers.

El usuario no tendra que instalar PHP, Laragon, SQLite, NSSM ni WinSW manualmente. Todo requisito del
motor viajara dentro de su instalador firmado.

## 6. Actualizaciones

### 6.1 Clientes Electron

Las actualizaciones de Administrativo, POS y Soporte reemplazaran solo su interfaz. No requeriran
detener los servicios ni elevar permisos y no incluiran una copia operativa del backend.

### 6.2 Motor Local

El Motor tendra version y canal propios. Su actualizacion sera explicita desde Soporte Tecnico o su
instalador y exigira UAC.

Flujo transaccional obligatorio:

1. descargar a `updates\staging`;
2. verificar firma/hash y compatibilidad;
3. preparar una nueva carpeta versionada;
4. ejecutar validaciones sin tocar el motor activo;
5. respaldar SQLite y configuracion;
6. detener servicios;
7. cambiar `current` a la nueva version;
8. aplicar migraciones;
9. iniciar servicios y comprobar health checks;
10. si falla cualquier comprobacion, restaurar version, configuracion y base anteriores;
11. conservar logs de instalacion y rollback.

Nunca se eliminara primero el motor funcional para luego intentar copiar el nuevo.

## 7. Comportamiento de las aplicaciones cuando el motor falla

La ventana debe crearse antes de esperar el backend. En lugar de cerrarse silenciosamente mostrara:

- estado del Motor Local;
- version requerida e instalada;
- resultado de los puertos `8787` y `17777`;
- ultima linea segura de diagnostico, sin tokens;
- botones `Reintentar`, `Abrir diagnostico` y `Reparar como administrador`;
- opcion para continuar cuando solo la nube este disponible, si el modo lo permite.

`Access is denied` nunca se considerara exito. Debe clasificarse como `requiere elevacion` y mostrarse
de forma comprensible.

## 8. Desinstalacion y propiedad de datos

- Desinstalar Administrativo, POS o Soporte no toca servicios, workers, SQLite, tokens ni storage.
- Desinstalar el Motor detiene y elimina sus servicios, pero conserva los datos por defecto.
- La eliminacion de SQLite, tokens y archivos sera una opcion separada llamada `Eliminar tambien los
  datos locales`, con confirmacion explicita.
- Solo el Motor puede actualizar o eliminar sus propios servicios.
- Los workers deben ejecutar herramientas del Motor, nunca rutas de Administrativo/POS/Soporte.

## 9. Migracion desde la instalacion actual

1. Inventariar clientes, tareas, procesos, workers, puertos y rutas legado.
2. Crear respaldo verificable de `inventario.sqlite`, `storage`, secretos y configuraciones de sync.
3. Instalar el Motor sin borrar la instalacion anterior.
4. Detener y retirar `InventarioArensBackend` e `InventarioArensPrinter` solamente cuando los nuevos
   servicios esten preparados.
5. Reescribir workers para usar el PHP/backend del Motor.
6. Iniciar y probar el nuevo Motor.
7. Abrir los tres clientes y ejecutar sincronizacion real.
8. Mantener rollback a la ruta legado durante al menos una version estable.

La primera implementacion no debe renombrar ni borrar datos en el mismo paso que introduce los
servicios nuevos. Primero se estabiliza el Motor; el retiro final del nombre legado sera una fase
posterior y reversible.

## 10. TDD y validacion obligatoria

### 10.1 Tests automatizados

- Parser y generador de configuracion del servicio.
- Ningun `electron-builder.*.yml` incluye o ejecuta el instalador del Motor.
- Ningun desinstalador Electron contiene acciones sobre servicios o datos compartidos.
- Los launchers usan `Motor\current`, nunca `resources` de un cliente.
- `Access is denied`, tarea/servicio ausente y timeout producen diagnostico visible.
- La ventana se crea aunque `8787` este caido.
- Migracion de ruta legado idempotente.
- Actualizacion fallida conserva la version anterior.
- Desinstalacion normal conserva SQLite y tokens.

### 10.2 Pruebas de integracion en Windows limpio

1. Instalar solo Motor y comprobar servicios despues de reiniciar Windows.
2. Finalizar forzosamente PHP y comprobar reinicio automatico.
3. Instalar los tres clientes en distinto orden.
4. Actualizar cada cliente mientras otro permanece abierto; `8787` no debe interrumpirse.
5. Desinstalar cada cliente; el Motor debe continuar saludable.
6. Simular fallo durante una actualizacion del Motor y verificar rollback.
7. Reiniciar sin sesion iniciada y comprobar backend, impresion y sync.
8. Probar una base existente con empresas vinculadas y confirmar que no pierde tokens ni eventos.
9. Ejecutar venta local, impresion y sincronizacion nube antes y despues de actualizar.

## 11. Criterios de aceptacion

La migracion se considera terminada solo cuando:

- `8787` y `17777` funcionan sin abrir Electron;
- matar los procesos provoca recuperacion automatica;
- ninguna actualizacion de interfaz detiene el Motor;
- ningun launcher depende de la carpeta de una aplicacion;
- las aplicaciones siempre muestran una ventana y un diagnostico accionable;
- instalar o desinstalar clientes no altera SQLite ni los servicios;
- una actualizacion fallida del Motor revierte automaticamente;
- los tres clientes y todos los workers usan la misma version activa del Motor;
- existe una prueba reproducible en una VM Windows limpia;
- el procedimiento de respaldo y recuperacion fue probado, no solo documentado.

## 12. Fases propuestas

1. **Contrato TDD y prototipo del servicio**: seleccionar wrapper, definir manifiestos y pruebas.
2. **Instalador Motor Local**: instalacion, health checks, logs, preservacion de datos y rollback.
3. **Clientes desacoplados**: retirar gestion de tareas/backend de los tres instaladores.
4. **Pantalla de diagnostico**: ventana temprana y reparacion elevada desde Soporte.
5. **Migracion de workers y rutas legado**.
6. **Laboratorio Windows limpio**: instalacion, fallos, actualizaciones y rollback.
7. **Release piloto**: una PC controlada antes de distribuir nuevos `.exe`.

No se publicara un release general hasta completar las fases 1 a 6 y guardar evidencia de los
resultados.
