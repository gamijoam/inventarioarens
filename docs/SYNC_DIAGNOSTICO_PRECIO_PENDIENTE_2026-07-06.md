# Diagnostico de precio pendiente de sincronizacion

Fecha: 2026-07-06

## Caso revisado

Producto: `Adaptador Bluetooth`

SKU: `ADP-BT-VAL`

Empresa: `demo-valencia`

El usuario cambio el precio a `USD 3000.00` desde el Centro de Inventario y noto que no se reflejaba en la nube.

## Resultado del diagnostico local

El precio si quedo guardado correctamente en PostgreSQL local:

```text
products.base_price = 3000.0000
products.sale_currency = USD
```

Tambien se genero el evento local de sincronizacion:

```text
sync_outbox.event_type = product.updated
sync_outbox.aggregate_type = product
sync_outbox.status = pending
payload.base_price = 3000.0000
```

Esto confirma que el problema no estaba en el formulario ni en el guardado local del producto.

## Problemas encontrados

1. El worker local de `demo-valencia` estaba detenido.
2. Al intentar iniciarlo, el script de Windows fallaba por una duplicidad de variables de entorno `Path/PATH`.
3. Luego de corregir el arranque, el worker intento conectar con la nube, pero el puerto configurado no respondio desde la computadora local.

Prueba de red:

```text
217.216.80.158:8010 -> no responde
217.216.80.158:8000 -> no responde
```

## Cambio aplicado

Se ajusto `scripts/sync-worker.ps1` para normalizar `Path/PATH` antes de iniciar el worker o ejecutar una sincronizacion manual. Esto evita que PowerShell falle antes de arrancar el proceso.

## Conclusiones

El precio local esta correcto y listo para subir.

Para que suba a la nube faltan dos condiciones:

1. La API del VPS debe estar activa y accesible desde esta computadora.
2. El worker local debe quedar activo o debe ejecutarse una sincronizacion manual sin errores de conexion.

## Comandos utiles

Revisar el worker local:

```powershell
.\scripts\sync-worker.cmd status -TenantSlug demo-valencia
```

Probar conexion al VPS desde Windows:

```powershell
Test-NetConnection 217.216.80.158 -Port 8010
```

En el VPS, revisar la API permanente:

```bash
systemctl status inventarioarens-cloud-api
journalctl -u inventarioarens-cloud-api -f
```

