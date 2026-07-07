# Dominio de la API nube: app.miinventariofacil.com

## Objetivo

Dejar la API nube del sistema accesible por un dominio profesional:

```text
https://app.miinventariofacil.com/api
```

Esto reemplaza el uso operativo de la IP con puerto, por ejemplo:

```text
http://217.216.80.158:8010/api
```

## Cambio realizado

Se configuro el VPS `217.216.80.158` con Nginx para servir el proyecto Laravel ubicado en:

```text
/opt/inventarioarens-cloud/public
```

Tambien se activo HTTPS con Let's Encrypt para:

```text
app.miinventariofacil.com
```

La API respondio correctamente por HTTPS. La prueba sobre una ruta protegida devolvio `401`, lo esperado cuando no se envia token.

## Registro DNS requerido en Cloudflare

El dominio debe tener este registro:

```text
Tipo: A
Nombre: app
Contenido: 217.216.80.158
TTL: Auto
Proxy: DNS only para la primera prueba
```

Cuando todo este estable, se puede evaluar activar el proxy de Cloudflare.

## URL oficial para configuradores y sincronizacion

Desde ahora, la URL recomendada para configuracion local es:

```text
https://app.miinventariofacil.com/api
```

Se actualizaron los valores sugeridos en:

- Configurador de instalacion local.
- Asistente de sincronizacion del escritorio.
- Script local de configuracion de nube.
- Script de bootstrap del VPS.

## Importante

Si una computadora ya tenia guardada una configuracion antigua con IP y puerto, no se sobrescribe automaticamente. Esa instalacion debe reconfigurarse o actualizar su URL nube a:

```text
https://app.miinventariofacil.com/api
```

## Validaciones recomendadas

Desde una computadora cliente:

```powershell
Test-NetConnection app.miinventariofacil.com -Port 443
```

En navegador:

```text
https://app.miinventariofacil.com/api/sync/status
```

Si responde `401` o un JSON de autenticacion, Laravel esta respondiendo correctamente.

## Pendiente recomendado

El proceso ideal para produccion es que el VPS no dependa de `php artisan serve`. Laravel debe quedar servido por Nginx y PHP-FPM, como quedo configurado para este dominio.

