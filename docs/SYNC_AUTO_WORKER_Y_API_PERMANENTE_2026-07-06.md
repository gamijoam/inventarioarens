# Sincronizacion automatica y API permanente

Fecha: 2026-07-06

## Objetivo

Evitar que la sincronizacion dependa de presionar manualmente el boton **Sincronizar ahora** y dejar clara la diferencia entre:

- la API de la nube, que debe quedar activa en el VPS;
- el worker local, que debe quedar activo en segundo plano por cada empresa configurada en esa computadora.

## Problema detectado

La sincronizacion manual funcionaba, pero la automatica no se ejecutaba luego de esperar el intervalo configurado.

La causa era que los workers locales estaban detenidos. Si el worker no esta activo, no importa que la frecuencia diga 15 o 30 segundos: no existe ningun proceso revisando cambios en segundo plano.

Tambien se aclaro que ejecutar `php artisan serve --host=0.0.0.0 --port=8010` en una consola del VPS es temporal. Si se cierra esa consola, la API se apaga.

## Cambio realizado en WPF

Al abrir el panel principal de una empresa, la aplicacion ahora hace esta revision:

1. Busca la configuracion local de sincronizacion de esa empresa.
2. Si la empresa tiene URL de nube y token guardados, consulta el estado del worker.
3. Si el worker esta detenido, lo inicia automaticamente.
4. Actualiza el indicador visual de sincronizacion.

Si la empresa no tiene configuracion completa, no intenta iniciar nada y deja el estado como pendiente para que el tecnico use el configurador.

## API permanente en el VPS

Para dejar la API de nube activa aunque cierres la consola, se usa el script:

```bash
cd /opt/inventarioarens-cloud
DB_PASSWORD='CLAVE_POSTGRES' APP_PORT=8010 bash scripts/cloud-api-bootstrap-vps.sh
```

Ese script crea y activa el servicio:

```bash
inventarioarens-cloud-api
```

Comandos utiles en el VPS:

```bash
systemctl status inventarioarens-cloud-api
systemctl restart inventarioarens-cloud-api
journalctl -u inventarioarens-cloud-api -f
```

## Punto importante del puerto

La URL configurada en cada empresa local debe coincidir con el puerto real de la API en el VPS.

Ejemplo correcto si la API permanente queda en 8010:

```text
http://217.216.80.158:8010/api
```

Si la configuracion local tiene `:8000/api` pero el servicio esta en `:8010/api`, el worker no va a sincronizar contra el servidor esperado.

## Resultado esperado

Despues de configurar una empresa con el instalador:

- el primer sincronizado descarga la informacion inicial;
- al abrir el sistema principal, el worker queda activo;
- cada intervalo configurado sube cambios locales y baja cambios de la nube;
- el boton manual queda solo como apoyo cuando el usuario quiera forzar una revision inmediata.

