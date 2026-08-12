# Guia de prueba fisica del agente de impresion

Esta guia verifica el flujo completo con una **impresora termica real** (EPSON TM-T20/T20II, Star
TSP650, Xprinter XP-58/80, etc.) en una PC local, tanto por driver de Windows como por red ESC/POS.

## 1. Pre-requisitos

- Backend local corriendo (Electron o `php artisan serve`).
- Agente `printer:serve` activo en `127.0.0.1:17777`.
  - Electron: se inicia solo (auto-arranque).
  - Manual: `php artisan printer:serve --port=17777`.
  - Consola `/support`: boton "Instalar agente" y "Iniciar".
- Usuario con permiso `printing.manage` (Gerente/Admin).

## 2. Modo A: impresora de red por TCP 9100 (recomendado para POS)

La impresora debe tener IP fija en la LAN (ej. `192.168.1.50`) y estar en el mismo segmento.

1. En `/printing` crea una estacion con:
   - `printer_type` = **Impresora de red (TCP 9100)**
   - `network_host` = IP de la impresora (ej. `192.168.1.50`)
   - `network_port` = `9100`
   - `output_mode` = **Termica** (o Termica + digital)
2. Asignale el perfil de ticket (58 o 80mm) segun el ancho del papel.
3. Activa en el perfil: **Cortar papel al terminar** y **Abrir gaveta al imprimir**.
4. Guarda la estacion y pulsa **Probar termica**.

Resultado esperado (si la impresora esta alcanzable):

- La impresora imprime el ticket de prueba.
- Al final **corta el papel** (GS V).
- Si tiene gaveta conectada en el conector RJ11, **se abre** (ESC p pin 2).

Si falla con "No se pudo conectar", verifica:

- `ping 192.168.1.50` desde la PC.
- Que el puerto 9100 este abierto: `Test-NetConnection 192.168.1.50 -Port 9100`.
- Que la impresora no este en modo "shared printer" sino "network/Ethernet".

## 3. Modo B: impresora con driver de Windows

1. Instala el driver de la impresora en Windows (ej. "EPSON TM-T20 Receipt") y anota el **nombre
   exacto** de la impresora en `Printers` (`control printers`).
2. En `/printing` crea una estacion con:
   - `printer_type` = **Impresora Windows (driver)**
   - `printer_name` = nombre exacto (puede llevar espacios).
   - `output_mode` = **Termica**.
3. Guarda y pulsa **Probar termica**.

Resultado esperado: el ticket se imprime por el driver (texto plano ASCII). No se ejecutan
comandos ESC/POS (sin corte ni gaveta) porque el driver gestiona el stream; el corte se puede
configurar en el driver/utilitario de la impresora si lo soporta.

## 4. Verificacion rapida sin papel

Health check del agente:

```bash
curl http://127.0.0.1:17777/health
# -> {"ok":true,"service":"inventarioarens-printer-agent","port":17777}
```

Desde la consola de soporte (`/support`):

- La tarjeta "Agente de impresion" muestra **Conectado**.
- Boton **Probar agente** -> "El agente responde en http://127.0.0.1:17777".

## 5. Flujo end-to-end con venta real

1. Abre el POS, abre caja, vende un producto y paga.
2. Al pagar se crea el `PrintJob` y el frontend lo envia al agente (`:17777`).
3. Revisa en el log del agente (`storage/logs/printer.log` o consola) que se registro
   `printed`/`generated`.
4. La impresora emite el ticket con los datos del perfil (cliente, items, IMEI, total, tasa).

## 6. Diagnostico de errores

| Sintoma | Causa | Fix |
|---|---|---|
| "No se pudo conectar a host:9100" | IP incorrecta / puerto cerrado / impresora apagada | `Test-NetConnection` y revisar red |
| "Estacion sin printer_name" | Estacion driver sin nombre | Configurar `printer_name` exacto |
| "Estacion de red sin network_host" | Estacion red sin IP | Configurar `network_host` |
| Imprime pero no corta | Modo driver (sin ESC/POS) o flag `cut_paper` apagada | Usar modo red TCP o activar flag |
| No abre la gaveta | Flag `open_cash_drawer` apagada o gaveta en otro pin | Activar flag; probar pin en el utilitario |
| Agente no responde en /support | Agente detenido | Boton "Instalar agente" + "Iniciar" |

## 7. Tests automatizados relacionados

- `tests/Unit/Printing/ThermalPrinterServiceTest.php` (sanitize + buildCommand).
- `tests/Unit/Printing/ThermalPrinterNetworkTest.php` (buildEscPos: corte GS V, gaveta ESC p;
  envio real por TCP).
- `tests/python/test_printer_serve.py` (agente: health, print digital, print thermal red, CORS).
- `tests/Feature/Printing/PrintingApiTest.php` + `PrintPreviewTest.php` (API, permisos, preview).
