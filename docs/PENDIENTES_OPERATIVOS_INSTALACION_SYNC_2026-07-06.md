# Pendientes operativos de instalacion y sincronizacion

## Contexto

El sistema ya cuenta con configurador independiente, validacion de requisitos, sincronizacion local-nube, worker automatico y tarea programada de Windows. Antes de cerrar completamente esta idea operativa para instalaciones en clientes reales, quedan algunas mejoras recomendadas.

## Pendientes recomendados

### 1. Crear base local desde el configurador

Si PostgreSQL esta instalado pero la base `inventory_arens` no existe, el configurador deberia permitir crearla desde un boton.

Objetivo:

- Evitar que el tecnico tenga que entrar a HeidiSQL o consola.
- Reducir errores de instalacion.
- Preparar instalaciones desde cero con menos pasos manuales.

Propuesta visual:

```text
Crear base local
```

Este boton deberia aparecer cuando la validacion detecte que PostgreSQL responde, pero la base local no existe.

### 2. Prueba guiada de sincronizacion

Agregar una opcion que ejecute una prueba completa local-nube.

Debe validar:

- Conexion local.
- Conexion nube.
- Token de sincronizacion.
- Subida de evento local.
- Bajada de evento desde nube.
- Confirmacion de aplicado.

Resultado esperado:

```text
Sincronizacion correcta
```

Si falla, debe mostrar un mensaje claro:

```text
No se pudo conectar con la nube.
Token invalido.
La base local no responde.
```

### 3. Paquete tecnico de instalacion

Preparar una carpeta o paquete para instalaciones reales.

Debe incluir:

- Configurador.
- Aplicacion principal.
- Scripts de sincronizacion.
- Guia rapida.
- Checklist de instalacion.
- Comandos de soporte.

Objetivo:

- Que el tecnico instale sin conocer el codigo.
- Evitar copiar archivos manualmente de forma desordenada.
- Facilitar soporte remoto.

### 4. Validacion automatica de permisos de instalacion

El configurador o instalador tecnico debe validar permisos antes de intentar sincronizar o ejecutar la app.

Permisos locales en Windows:

- La carpeta del sistema debe permitir lectura y escritura al usuario operativo.
- `storage/logs` debe permitir crear y escribir logs.
- `storage/app/sync-worker` debe permitir crear configuracion, PID y archivos temporales del worker.
- El worker automatico debe quedar instalado como tarea de Windows con el mismo usuario que tiene acceso a la carpeta del sistema.
- El usuario debe poder ejecutar PHP, .NET y los scripts `scripts/sync-worker.cmd` y `scripts/sync-worker-task.cmd`.

Permisos de PostgreSQL local:

- El usuario configurado debe poder conectarse a la base local.
- Debe poder crear tablas durante migraciones.
- Debe poder leer y escribir datos operativos.
- Si la base no existe, el configurador deberia permitir crearla o mostrar una instruccion clara.

Permisos en VPS o servidor nube:

- El usuario del servicio web debe poder leer el proyecto.
- `storage` y `bootstrap/cache` deben ser escribibles por el usuario que ejecuta Laravel.
- El servidor debe poder escribir logs de Laravel.
- El backend nube debe tener acceso a PostgreSQL con permisos de lectura/escritura sobre la base `inventory_arens`.
- La API nube debe quedar expuesta por HTTPS para los locales.
- El puerto directo de PostgreSQL solo debe abrirse si es estrictamente necesario para soporte tecnico; en produccion se recomienda usar la API y restringir PostgreSQL por firewall.

Validaciones recomendadas del instalador:

- Conexion al backend nube.
- Token de sincronizacion valido.
- Escritura en `storage/logs`.
- Escritura en `storage/app/sync-worker`.
- Estado de la tarea automatica de Windows.
- Conexion a PostgreSQL local.
- Migraciones locales aplicadas.
- Primer ciclo de sincronizacion local-nube.

Resultado esperado:

```text
Instalacion lista para operar
```

Si algo falla, el instalador debe mostrar mensajes no tecnicos, por ejemplo:

```text
No puedo escribir logs en esta carpeta.
PostgreSQL local no responde.
La tarea automatica de sincronizacion no esta instalada.
El token de nube no corresponde a esta empresa.
```

## Orden sugerido

1. Crear base local desde configurador.
2. Prueba guiada de sincronizacion.
3. Paquete tecnico de instalacion.
4. Validacion automatica de permisos de instalacion.

## Nota

Estos puntos quedan pendientes porque se van a priorizar nuevas ideas funcionales antes de continuar con el cierre completo del flujo de instalacion.
