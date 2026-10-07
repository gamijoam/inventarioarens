# Auditoría y propuesta del módulo Cajas — BalanzaPro

Fecha: 2026-10-07. Objetivo: `app.balanzapro.com/cash-register`.
Código auditado: `/opt/balanzapro-cloud`.

## 1. Resumen ejecutivo y alcance

La pantalla reúne operación diaria, supervisión, conteo de cierre y configuración
sin una jerarquía adecuada. La solución recomendada es organizarla por tarea y
perfil, mostrando el detalle cuando se necesita. Hay además defectos de permisos,
coherencia monetaria y contratos que deben acompañar el rediseño.

Auditoría de código frontend/backend y ejecución de pruebas aisladas. No se realizó
una sesión de navegador autenticada ni una medición visual en dispositivos reales;
las conclusiones de interfaz se basan en componentes, estilos y flujos implementados.
No se modificó funcionalidad ni se desplegó un rediseño. Las propuestas de implementación
se limitan a BalanzaPro, sin sincronizar otras instalaciones del repositorio.

## 2. Evidencia ejecutada

- Backend existente: **37/37**, 246 assertions, `tests/Feature/CashRegister`.
- Frontend existente: **19/19** en `CashRegisterSetup.test.tsx`,
  `CashRegisterCommandCenter.test.tsx` y `CashPanel.test.tsx`.
- Comprobaciones temporales de auditoría: **3/3**, 14 assertions,
  `/tmp/opencode/CashAuditEvidenceTest.php`. Confirman comportamientos defectuosos
  actuales; no significan que esos comportamientos sean aceptables.
- Backend ejecutado en SQLite `:memory:`, configuración de testing y caché de
  configuración separada mediante `APP_CONFIG_CACHE`.
- No se realizaron operaciones de caja sobre datos productivos ni pruebas de
  concurrencia PostgreSQL. No se ejecutó la suite global.

Comando de backend:

```bash
APP_ENV=testing APP_CONFIG_CACHE=/tmp/opencode/cash-audit-no-config.php DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync php vendor/bin/phpunit -c phpunit.sqlite.xml tests/Feature/CashRegister
```

## 3. Hallazgos de lógica y permisos

### L1 — Alta: restricción por sucursal inconsistente (reproducido)

El listado aplica `ScopeResolver`, pero la policy de lectura individual verifica
empresa y permiso, no sucursal. El reporte de sesiones tampoco aplica el alcance
de sucursales del usuario. Con un usuario limitado a otra sucursal, el listado no
devuelve la sesión, pero su detalle y el reporte sí.

Evidencia: `CashRegisterSessionController.php:29-64,97-112`,
`CashRegisterSessionPolicy.php:16-20`,
`OperationalReportService.php:199-260`.
Es un problema de alcance dentro de la misma empresa, no un cruce entre tenants
demostrado. Extender la revisión a cierre, revisión y Reporte Z, que tampoco
incorporan una comprobación explícita de sucursal en sus autorizaciones revisadas.

Propuesta: una regla de alcance compartida por listado, detalle, reportes y acciones,
con pruebas negativas por empresa y sucursal.

### L2 — Alta: asignación de otro cajero sin autorización específica (reproducido)

Con solo `cash_register.open` se puede enviar `cashier_id` de otro miembro y abrirle
un turno. El frontend asocia esta opción con `cash_register.close`, pero el backend
no exige esa autorización ni una capacidad equivalente de supervisión.

Evidencia: `OpenCashRegisterSessionRequest.php:29-33`,
`CashRegisterSessionPolicy.php:22-25`, `CashRegisterService.php:29-39`.

Propuesta: distinguir abrir el propio turno de abrir para otra persona. El selector
de cajeros debe contener operadores habilitados, no todos los usuarios activos.

### L3 — Alta: totales y denominaciones pueden contradecirse (reproducido)

Se pudo declarar `counted_base_amount=100`, `counted_cash_usd=100` y un billete
de $10, obteniendo diferencia física cero y un desglose guardado de $10.

Evidencia: `CashRegisterService.php:482-519,582-599`.
El servidor prioriza montos explícitos, sin comprobar coherencia con denominaciones.
Existe un caso legítimo de USD por billetes y Bs manual que debe conservarse.

Propuesta: modo de conteo por moneda. Si USD se cuenta por billetes, su total se
deriva o valida contra esos billetes; Bs puede permanecer manual. El servidor es
la fuente de verdad. Rechazar duplicados de moneda/denominación con 422 antes del
índice único de la base.

### L4 — Alta: el cierre ciego no constituye un control real (inspección)

El toggle solo oculta parte de `ClosePanel`. El resumen del turno y la tarjeta
superior siguen mostrando esperado. La API entrega esos importes sin condición.
Además, el propio cajero puede alternar el modo y `blind` exime la justificación
de diferencias en el backend.

Evidencia: `CashRegisterSetup.tsx:96-100,612-615,640-690`,
`CashRegisterSessionResource.php:23-34`, `CashRegisterService.php:187-193`.

Propuesta: decidir si es una simple ayuda visual o una política real de arqueo.
Para un arqueo ciego real, configurar el modo por empresa/perfil y restringir
esperados en todos los endpoints, reportes y vistas relevantes para ese cajero.
Registrar primero la declaración y después mostrar el resultado al responsable.

### L5 — Alta: apertura equivalente rotulada como dólares físicos (inspección)

El backend guarda `opening_base_amount` como USD + equivalente USD de Bs.
El frontend lo etiqueta como `Fondo USD` y muestra además `Fondo VES`.
Ejemplo ilustrativo: $10 y Bs 400, con tasa 40, producen base equivalente $20;
la pantalla puede presentar Fondo USD $20 y Fondo VES Bs 400, sugiriendo más
efectivo del que existe.

Evidencia: `CashRegisterService.php:85-125`, `CashRegisterSetup.tsx:611-615`.

Propuesta: exponer apertura física por moneda a partir de movimientos de apertura
y reservar la conversión para un campo explícitamente llamado equivalente.
No reinterpretar ni sobrescribir importes históricos existentes.

### L6 — Media: alertas mezclan diferencias físicas y equivalentes (inspección)

La lógica separa correctamente el efectivo de los pagos electrónicos al calcular
`expected_cash_*`. Sin embargo, sigue existiendo `difference_base_amount`, que
compara el conteo de efectivo convertido con movimientos de todos los métodos.
`SessionBreakdown` usa ese campo para una advertencia, mientras la tabla utiliza
la diferencia física. Puede advertir sobre un cierre físicamente correcto.

Evidencia: `CashRegisterService.php:202-205,549-579`,
`CashRegisterCommandCenter.tsx:177-183,238,462-465`.

Propuesta: separar efectivo, cobros electrónicos y equivalentes contables;
usar solo diferencia física por moneda para faltantes/sobrantes de gaveta.
No netear faltantes y sobrantes de varias cajas como único indicador.

### L7 — Media: reglas y permisos divergentes entre frontend y API

- La UI habilita movimientos por `cash_register.move` O `cash_register.movements`;
  la policy requiere el primero. Hay acciones ofrecidas que pueden recibir 403.
- Los botones de cierre en historial dependen solo del permiso; el servidor exige
  además ser dueño del turno o supervisor por rol.
- El frontend exige cajero seleccionado a quien tiene permiso de cierre, incluso
  para abrir su propio turno, y consulta usuarios sin condicionar por permiso.
- La API permite sesiones sin caja física; `activeSession` las descarta en esta
  pantalla. Puede existir un turno abierto que impida abrir otro y no aparezca
  como turno operativo propio.

Evidencia: `CashRegisterSetup.tsx:49-76,506,566-569`,
`CashRegisterSessionPolicy.php:27-49`, `OpenCashRegisterSessionRequest.php:24-28`.

Propuesta: capacidades por sesión devueltas por backend, autocajero predeterminado,
y una regla explícita para sesiones legacy sin caja física.

### L8 — Media: ajustes ambiguos y reintentos sin clave idempotente

`adjustment` admite monto positivo y el cálculo resta únicamente `outflow`;
un ajuste siempre suma. La interfaz no explica esa dirección.
Las rutas admiten idempotencia, pero los hooks de caja no envían `Idempotency-Key`
y el cliente HTTP no la genera. Un reintento de movimiento puede duplicarlo.

Evidencia: `StoreCashRegisterMovementRequest.php:19-43`,
`CashRegisterService.php:560-563`, `pos/api.ts:1447-1528`, `api/client.ts:71-76,157-172`.

Propuesta: entradas y retiros como acciones ordinarias; correcciones por
contramovimiento trazable y permiso específico. Clave estable por intento lógico.

### L9 — Media: cierre y revisión necesitan contratos más claros

- El formulario convierte campos vacíos a cero y puede enviar un cierre sin conteo
  explícito, particularmente en modo ciego. Distinguir vacío de cero confirmado.
- Una nota de uno o dos caracteres habilita acciones en UI pero el servidor exige
  al menos tres.
- Revisión sobrescribe estado/notas del mismo registro. En el servicio no hay una
  transición restringida ni registro propio append-only de revisiones sucesivas;
  verificar auditoría transversal antes de definir el historial definitivo.

Evidencia: `CashRegisterSetup.tsx:156-185,648-690`,
`CloseCashRegisterSessionRequest.php:39`, `CashRegisterService.php:247-265`.

### L10 — Riesgo pendiente: apertura simultánea del mismo cajero

Se bloquea la caja física, pero la comprobación de turno abierto del cajero es
`exists()` sin bloqueo del cajero. Dos aperturas para el mismo cajero en cajas
distintas pueden competir. No se encontró en las migraciones revisadas un índice
único parcial que impida dos turnos abiertos por cajero.

Evidencia: `CashRegisterService.php:41-83`.
Pendiente reproducción concurrente en PostgreSQL aislado. Las pruebas actuales de
doble apertura son secuenciales y SQLite no valida esta condición de carrera.

## 4. Hallazgos de interfaz y rendimiento

### U1 — Pantalla sin prioridad operativa

Hero grande, badges repetidos, cuatro tarjetas superiores y cierre completo
permanente compiten con las acciones. El texto visible incluye una nota de
implementación: «La lógica ... se mantiene intacta. Solo reorganizamos ...».
Ese texto no ayuda al cajero.

Evidencia: `CashRegisterSetup.tsx:194-240,451-470`.

### U2 — Conteo avanzado obligatorio visualmente

Los 12 campos de denominaciones USD y 11 VES aparecen siempre, junto con entrada
manual, declarado, esperado, diferencias y fórmula equivalente. Tocar una sola
denominación activa el modo calculado para ambas monedas y bloquea ambos totales
manuales; puede descartar el monto manual de la otra moneda.

Evidencia: `CashRegisterSetup.tsx:36-39,648-674,825-837`.

### U3 — Operación incompleta y vocabulario técnico

Movimientos manuales solo en USD: `currency: 'USD'` fijo. Etiquetas de movimientos
renderizan `inflow`, `outflow`, etc. directamente; estados de configuración muestran
`active`. Hay referencias, códigos y correos que pueden quedar en detalle.

Evidencia: `CashRegisterSetup.tsx:134-149,629,727,775`.
Propuesta: «Agregar efectivo», «Retirar efectivo», «Dólares», «Bolívares»,
«Faltan», «Sobran» y «Comprobante de cierre»; conservar el número Z como identificador.

### U4 — Supervisión, historial y configuración poco diferenciados

Supervisión e historial repiten sesiones e importes. La pestaña Supervisión se
ofrece incluso cuando su contenido devuelve null por permisos. Configuración
mezcla altas de sucursales y cajas con la rutina del cajero.
El supervisor aterriza siempre en «Mi turno», aunque no opere una gaveta.

### U5 — Alertas poco fiables para priorizar trabajo

Todo turno abierto cuenta como «requiere atención», sin antigüedad ni incidente.
La tabla principal enfatiza diferencia USD; la diferencia Bs queda en detalle.
Una diferencia solo en Bs puede no verse en la columna principal. El reporte
incluye turnos abiertos fuera de la fecha elegida, algo que la UI no aclara.

Evidencia: `CashRegisterCommandCenter.tsx:177-183,229-232,275-277`,
`OperationalReportService.php:204-207`.

### U6 — Historial recortado y sobrecarga de datos

- Historial muestra 50 abiertos y 25 cerrados sin navegación; el hook descarta meta.
- Supervisión solicita límite 100 y no presenta controles de paginación.
- El indicador de abiertos usa longitud de la página, no el total real.
- Ocho hooks de lectura se montan de entrada: sucursales, cajas, turno propio,
  abiertos, cerrados, tasas, tipos de tasa y usuarios, aunque pestañas estén ocultas.
- Listado carga todos los movimientos de cada sesión; el detalle carga órdenes y
  pagos completos aunque el panel muestre solo un resumen.
- Cada movimiento recalcula todos los movimientos del turno. Es un riesgo de coste
  creciente; no se midió una degradación productiva en esta auditoría.

Evidencia: `CashRegisterSetup.tsx:42-57,91-94`, `pos/api.ts:957-970`,
`CashRegisterSessionController.php:33-35,101-112`, `CashRegisterService.php:549-553`.

### U7 — Estados de error y accesibilidad

Varios hooks usan `data=[]` y no muestran error en la pantalla principal, pudiendo
confundir error con «no hay cajas/turnos». Hay inputs con solo placeholder,
tipografía de 10px, scroll anidado y tabla de 920px mínimos. Requiere validación
en móvil, teclado y lector de pantalla durante el rediseño.

### U8 — Actualización de supervisión incompleta

Movimientos, apertura y cierre invalidan queries POS, pero no el reporte
`['reports','cash-sessions']`; revisión sí lo hace. Los eventos realtime de caja
tampoco invalidan ese reporte. Se recomienda actualización coherente entre vistas,
con estado «Actualizado hace…» y recuperación explícita ante error.

Evidencia: `pos/api.ts:984-1004,1447-1528`, `useRealtimeSync.ts:38-45`.

### U9 — Reporte de cierre mezcla caja y análisis comercial

Categorías y clientes pueden vivir en «Análisis de ventas». El cierre debería
priorizar fondos, entradas/salidas, efectivo contado y diferencias por moneda.
El servicio agrega una venta completa a cada categoría de un producto multicategoría:
sumar las categorías puede duplicar totales. Además usa nombres/categorías actuales
al reconstruir el reporte; no es un snapshot íntegro e inmutable del cierre.

Evidencia: `ReportZService.php:98-154,195-205`, `ReportZDialog.tsx:149-157`.
Propuesta: comprobante de cierre estable y análisis comercial separado, con regla
explícita para productos multicategoría y datos históricos.

## 5. Lo que conviene conservar

- Transacciones y bloqueo de sesión para movimientos/cierre.
- Separación de efectivo USD/VES respecto de pagos electrónicos.
- Snapshots monetarios en movimientos y pagos.
- Rechazo de nuevos movimientos en turnos cerrados.
- Controles tenant-scoped y pruebas cross-tenant existentes.
- Consecutivo Z por caja protegido por bloqueo y unicidad.
- Reembolsos registrados como egresos trazables, sin borrar el pago original.

## 6. Propuesta de experiencia

### Estructura

1. **Mi caja**: inicio para cajero.
2. **Todas las cajas**: inicio para supervisor; visible con alcance autorizado.
3. **Cierres**: historial paginado y pendientes de revisión.
4. **Administrar cajas**: acción secundaria para administradores; las sucursales
   se gestionan desde su módulo y se enlazan cuando hagan falta.

### Mi caja — sin turno

Una tarjeta «Abre tu caja para comenzar»: caja disponible, fondo USD y fondo Bs,
botón Abrir. Autoseleccionar si solo hay una opción autorizada. Cajero actual
por defecto; «Abrir para otra persona» solo con autorización específica.
Excluir o marcar como ocupadas las cajas con turno activo.

### Mi caja — en operación

```text
Cajas                                  [Ir a vender]
Caja principal · Ana · Abierta desde 8:00

[Efectivo USD] [Efectivo Bs] [Ventas del turno]

[Agregar efectivo] [Retirar efectivo] [Cerrar caja]

Últimos movimientos                       [Ver todos]
Hora     Concepto               Moneda       Importe
```

El esperado se muestra solo si la política de conteo lo permite. Para cierre ciego
real, sustituir los indicadores que revelan el saldo por estado y actividad permitida.
Movimientos recientes paginados con etiquetas comprensibles y enlaces al origen.

### Agregar o retirar efectivo

Formulario breve: moneda, importe y motivo. Motivos configurables como fondo
adicional, retiro a resguardo o gasto menor. Referencia opcional en detalle.
Siempre mostrar caja y responsable. Evitar el ambiguo «Ajuste» para el cajero.

### Cerrar caja — flujo dedicado

1. **Contar**: dos importes claramente etiquetados. Opción «Contar billetes»
   desplegable e independiente por moneda. Cero explícito, no inferido de vacío.
2. **Revisar**: en modo normal, esperado/contado/faltante o sobrante por moneda;
   en modo ciego, solo declaración hasta confirmarla. Pedir motivo cuando corresponda.
3. **Confirmar**: identificar caja y cajero, bloquear doble envío y emitir
   comprobante. Mostrar resultado y opciones «Imprimir / Descargar / Volver».

### Supervisor

Lista de cajas con responsable, antigüedad, estado y acciones permitidas.
Separar «Abiertas», «Pendientes de revisión» y «Con diferencias». No tratar todos
los turnos abiertos como incidentes. Definir umbral de antigüedad configurable.
Detalle lateral con resumen, movimientos y revisión; filtros avanzados plegados.

### Cierres

Historial con fecha/rango, caja, cajero, estado de revisión y diferencia USD/Bs.
Paginación real y búsqueda. «Ver cierre» abre un único detalle coherente que
centraliza comprobante, conteo y revisión.

## 7. Funciones nuevas recomendadas, en orden

1. **Conteo por moneda independiente**, con validación backend.
2. **Motivos de entrada/retiro** y trazabilidad de correcciones.
3. **Política de arqueo por empresa**: normal/ciego, responsables y revisión.
4. **Borrador de conteo** por empresa, usuario y sesión; recuperar al reabrir y
   eliminar tras cierre confirmado. No usar el borrador como saldo oficial.
5. **Arqueo intermedio sin cerrar turno**, como declaración con fecha propia.
6. **Entrega de efectivo/relevo**: registrar entrega y recepción explícitas;
   no convertir automáticamente un cierre en apertura ni transferir propiedad
   de movimientos ya realizados.
7. **Conciliación de cobros electrónicos** separada del efectivo, como fase posterior.

## 8. Secuencia de implementación propuesta

### Fase 1 — Contratos y controles

Corregir L1-L5, autorización por acción/sucursal, coherencia de conteos, apertura
física y política de cierre. Añadir pruebas que esperen rechazo de los tres casos
reproducidos. Definir efectos de compatibilidad para sesiones históricas.

### Fase 2 — Operación simple

Nueva vista Mi caja, formularios breves, soporte USD/Bs, cierre guiado,
estados de error y textos de usuario. Compartir reglas de conteo con el POS para
evitar divergencia entre sus formularios.

### Fase 3 — Supervisión e historial

Vista por perfil, alertas accionables, paginación, query de resumen liviana,
detalle bajo demanda e invalidación coherente.

### Fase 4 — Mejoras opcionales

Arqueo intermedio, borrador, entrega de efectivo y conciliación electrónica.
Activar gradualmente según necesidad operativa, sin saturar la vista base.

## 9. Criterios de aceptación

- Apertura, entrada/retiro y cierre completos con teclado y móvil.
- El cierre no se monta en la vista diaria; denominaciones son opcionales.
- Montos y saldos conservan su moneda; las conversiones se identifican como tales.
- Ninguna acción ofrecida contradice la autorización del backend.
- No se filtran turnos fuera del alcance por detalle o reporte.
- Denominaciones y montos declarados coinciden por moneda.
- Reintentar un movimiento no genera duplicados.
- Historial permite recorrer todos los registros autorizados.
- Alertas distinguen USD y Bs, faltante y sobrante, y no marcan abierta = incidente.
- Pruebas de apertura simultánea en PostgreSQL aislado, además de unitarias,
  contratos JSON y pruebas de flujos UI con permisos distintos.
- Despliegue y verificación exclusivamente en `app.balanzapro.com`.
