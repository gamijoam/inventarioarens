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

## Orden sugerido

1. Crear base local desde configurador.
2. Prueba guiada de sincronizacion.
3. Paquete tecnico de instalacion.

## Nota

Estos puntos quedan pendientes porque se van a priorizar nuevas ideas funcionales antes de continuar con el cierre completo del flujo de instalacion.
