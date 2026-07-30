# Laboratorio de carga multi-tenant

Este laboratorio mide la experiencia web de varias empresas trabajando en paralelo. No requiere
workers locales: las empresas de prueba viven y operan directamente en la API de la nube.

## Escenarios disponibles

### Lectura multi-tenant

- Inicio de sesion independiente para tres empresas antes de iniciar la carga.
- Dashboard, catalogo y sesion autenticada por cada empresa.
- Aislamiento: un usuario de una empresa no puede encontrar el SKU de otra.
- Latencia p95/p99 y tasa de errores de las rutas consultadas.

La prueba de lectura crea una sesion inicial por empresa y luego mide exclusivamente trafico
operativo autenticado; no crea ventas ni modifica existencias, por lo que puede repetirse durante
una ventana de pruebas sin alterar la operacion.

### POS, caja e inventario serializado

El mismo comando de preparacion deja cada empresa de laboratorio con una caja fisica activa, un
turno abierto del operador de carga, una lista de precio predeterminada con efectivo USD permitido,
inventario por cantidad y un producto serializado con 100 IMEIs disponibles.

El escenario POS crea ventas pagadas reales solamente dentro de los tenants `loadtest-*`. Alterna
ventas por cantidad y ventas con IMEI, valida que el reintento con la misma llave no duplique la
orden y mide la latencia del checkout. No lo ejecutes contra empresas operativas.

### Colision de inventario POS

Cada empresa incluye dos productos exclusivos de carrera: uno por cantidad y otro con un unico
IMEI. Varios cajeros intentan vender la misma unidad al mismo tiempo. La prueba es correcta solo
si exactamente una venta queda pagada y los demas intentos reciben un rechazo controlado de stock
o serial. Cualquier respuesta 500, venta adicional o doble consumo de IMEI hace fallar el reporte.

## Preparar datos

Ejecuta el comando en el entorno donde vas a probar. Crea o actualiza solamente empresas cuyo slug
empieza por el prefijo indicado:

```powershell
php artisan stress:seed --tenants=3 --products=100 --prefix=loadtest --password='UnaClaveDePruebaSegura' --force
```

Se crean estos usuarios:

| Empresa | Usuario |
| --- | --- |
| `loadtest-01` | `loadtest-01@loadtest.local` |
| `loadtest-02` | `loadtest-02@loadtest.local` |
| `loadtest-03` | `loadtest-03@loadtest.local` |

El comando se niega a correr en produccion salvo que se agregue `--allow-production`. Usalo solo
en una ventana aprobada y con un prefijo exclusivo de pruebas.

## Ejecutar desde Windows

La ejecucion usa la imagen oficial de k6 con Docker Desktop, por lo que no necesitas instalar k6
en cada equipo.

```powershell
.\scripts\run-stress-lab.ps1 `
  -Password 'UnaClaveDePruebaSegura' `
  -BaseUrl 'http://127.0.0.1:8000/api' `
  -Vus 9 `
  -Duration '1m'
```

Para el VPS, apunta a `https://app.miinventariofacil.com/api` y confirma el destino de forma
explicita:

```powershell
.\scripts\run-stress-lab.ps1 `
  -Password 'UnaClaveDePruebaSegura' `
  -BaseUrl 'https://app.miinventariofacil.com/api' `
  -Vus 12 `
  -Duration '2m' `
  -AllowProduction
```

## Ejecutar POS e inventario

Primero prepara o restablece el laboratorio. Para el escenario POS usa al menos 10 productos, pues
el ultimo producto se reserva como producto serializado:

```powershell
php artisan stress:seed --tenants=3 --products=100 --prefix=loadtest --password='UnaClaveDePruebaSegura' --force
```

Luego ejecuta una cantidad acotada de ventas por cajero:

```powershell
.\scripts\run-stress-lab.ps1 `
  -Scenario pos `
  -Password 'UnaClaveDePruebaSegura' `
  -BaseUrl 'http://127.0.0.1:8000/api' `
  -Vus 6 `
  -Iterations 5 `
  -Products 100
```

Para una ventana aprobada en el VPS, comienza con `-Vus 3 -Iterations 3`, observa los reportes y
sube gradualmente. En el VPS aplica tambien `-PosP95Ms 1500` como objetivo inicial. Esta prueba
modifica stock, ventas y caja de los tenants de laboratorio.

## Ejecutar colision de inventario

Antes de cada corrida vuelve a preparar los datos. Esto restablece la unidad y el IMEI exclusivos
del laboratorio. Ejecuta los dos casos por separado:

```powershell
php artisan stress:seed --tenants=3 --products=100 --prefix=loadtest --password='UnaClaveDePruebaSegura' --force

.\scripts\run-stress-lab.ps1 `
  -Scenario race `
  -Password 'UnaClaveDePruebaSegura' `
  -BaseUrl 'http://127.0.0.1:8000/api' `
  -Vus 8 `
  -RaceTarget quantity
```

```powershell
php artisan stress:seed --tenants=3 --products=100 --prefix=loadtest --password='UnaClaveDePruebaSegura' --force

.\scripts\run-stress-lab.ps1 `
  -Scenario race `
  -Password 'UnaClaveDePruebaSegura' `
  -BaseUrl 'http://127.0.0.1:8000/api' `
  -Vus 8 `
  -RaceTarget serialized
```

El lanzador usa `k6` del equipo cuando esta disponible y, si no, usa Docker Desktop. En el VPS
hazlo solo en una ventana aprobada y agrega `-AllowProduction -RaceP95Ms 1500`.

## Interpretar el resultado

- `http_req_failed`: debe quedar por debajo de 1%.
- `http_req_duration p(95)`: objetivo inicial menor a 800 ms para lectura y 1.5 s para checkout.
- `http_req_duration p(99)`: objetivo inicial menor a 1.5 s para lectura y 3 s para checkout.
- `tenant_isolation_ok`: debe ser exactamente 100%. Cualquier valor menor indica una fuga de datos
  entre empresas y se trata como incidente de seguridad.
- `inventoryarens_pos_checkout_ok`: debe ser 100%. Un valor menor implica una venta rechazada o
  fallida durante la carga.
- `inventoryarens_pos_serialized_checkout_ok`: debe ser 100%. Un valor menor requiere revisar
  IMEIs, reserva de unidades o concurrencia del POS.
- `inventoryarens_pos_race_success`: debe ser exactamente `1`. Confirma que solo un cajero logro
  vender la ultima unidad.
- `inventoryarens_pos_race_valid_response`: debe ser 100%. Las respuestas validas son una venta
  exitosa y rechazos controlados de inventario; un 500 es una falla.

En el escenario de colision, `http_req_failed` puede verse alto porque k6 clasifica los `422` de
stock o IMEI agotado como respuestas no exitosas. No se usa como criterio de esa prueba: revisa los
dos indicadores `inventoryarens_pos_race_*`, que distinguen el rechazo esperado de un fallo real.

Empieza con 9 usuarios virtuales (3 por empresa), luego 12 y 24. No aumentes de golpe: observa
primero CPU, memoria, PostgreSQL, logs `PERF` y errores de nginx/PHP-FPM en el VPS.

## Siguientes escenarios

La siguiente ampliacion del laboratorio sera sincronizacion local/nube de extremo a extremo y
operaciones financieras controladas: CxC, CxP, devoluciones y traslados. Cada uno tendra tenants
y datos desechables separados para no mezclar resultados con el uso diario.
