# Configurador e instalación automática del worker

## Objetivo

El configurador independiente debe preparar una computadora nueva para trabajar con una empresa sin que el técnico tenga que ejecutar comandos manuales de sincronización.

## Flujo esperado

Al presionar **Configurar e instalar sincronización**, el asistente ejecuta el flujo completo:

1. Valida el acceso del administrador contra la nube.
2. Genera o registra el token seguro de sincronización para la empresa seleccionada.
3. Ejecuta migraciones locales para preparar la base `inventory_arens`.
4. Registra la empresa y el usuario localmente.
5. Guarda la configuración local del nodo.
6. Detiene cualquier worker anterior de esa empresa.
7. Ejecuta una sincronización inicial para bajar datos desde la nube.
8. Instala la tarea automática de Windows.
9. Inicia el worker local para que quede sincronizando en segundo plano.

## Qué instala Windows

El asistente usa `scripts/sync-worker-task.cmd install` para crear una tarea llamada:

```text
SistemaInventarioSync-{empresa}
```

Esa tarea revisa cada 5 minutos si el worker sigue vivo. Si el worker se detuvo, Windows lo vuelve a levantar.

El intervalo real de sincronización lo controla la configuración elegida en el asistente: cada 15, 30 o 60 segundos.

## Botones manuales

Los botones de soporte del configurador no son parte obligatoria de una instalación normal:

- **Reinstalar automático en Windows**: repara o vuelve a crear la tarea automática.
- **Iniciar**: levanta el worker en ese momento.
- **Detener**: detiene el worker activo.
- **Estado**: consulta si la tarea y el worker están activos.

## Criterio operativo

En una computadora nueva, el técnico solo debe:

1. Abrir el configurador.
2. Escribir URL de nube, correo y contraseña.
3. Buscar empresas disponibles.
4. Seleccionar la empresa.
5. Elegir el nombre del equipo y frecuencia.
6. Presionar **Configurar e instalar sincronización**.

Al terminar, la computadora queda lista para abrir el Sistema de Inventario y sincronizar automáticamente.
