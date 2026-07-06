# Sincronizacion automatica en Windows

## Objetivo

El configurador independiente del sistema ahora puede dejar instalada la sincronizacion local-nube como una tarea de Windows. Esto permite que la computadora siga sincronizando aunque el usuario cierre el Sistema de Inventario o reinicie Windows.

## Archivos agregados o ajustados

- `scripts/sync-worker-task.cmd`: entrada simple para ejecutar acciones sobre la tarea de Windows.
- `scripts/sync-worker-task.ps1`: instala, desinstala, inicia, detiene y consulta la tarea automatica por empresa.
- `scripts/sync-worker.ps1`: ahora puede leer `storage/app/sync-worker/sync-config.json` para obtener URL nube, token, nodo, instalacion e intervalo sin pasar esos datos manualmente.
- `desktop/InventorySyncInstaller`: agrega botones para instalar, iniciar, detener y consultar la sincronizacion automatica desde una ventana para tecnicos.

## Funcionamiento

Cada empresa configurada localmente queda identificada por su `tenant_slug`. Para esa empresa se guarda una configuracion local con:

- URL de la nube.
- Token de sincronizacion.
- Codigo de nodo.
- Nombre de la computadora.
- Codigo de instalacion.
- Intervalo configurado.

La tarea de Windows se crea con el nombre:

```text
SistemaInventarioSync-{empresa}
```

Ejemplo:

```text
SistemaInventarioSync-demo-valencia
```

La tarea ejecuta un lanzador local cada 5 minutos. Ese lanzador llama al worker con la empresa correspondiente. Si el worker ya esta activo, no abre otro duplicado; si esta detenido, lo levanta.

## Uso desde el configurador

1. Abrir `InventorySyncInstaller`.
2. Escribir URL nube, correo y contrasena.
3. Buscar empresas disponibles.
4. Seleccionar la empresa a configurar.
5. Presionar `Configurar esta computadora`.
6. Al terminar, el configurador prepara la base local, sincroniza datos iniciales e instala la sincronizacion automatica.

Tambien se pueden usar los botones:

- `Instalar automatico en Windows`: crea o actualiza la tarea y arranca el worker.
- `Iniciar`: levanta el worker ahora.
- `Detener`: detiene el worker activo.
- `Estado`: muestra si la tarea existe y si el worker esta activo.

## Comandos manuales de soporte

Consultar estado:

```powershell
.\scripts\sync-worker-task.cmd status -TenantSlug demo-valencia
```

Instalar tarea automatica:

```powershell
.\scripts\sync-worker-task.cmd install -TenantSlug demo-valencia
```

Detener worker:

```powershell
.\scripts\sync-worker-task.cmd stop -TenantSlug demo-valencia
```

Eliminar tarea automatica:

```powershell
.\scripts\sync-worker-task.cmd uninstall -TenantSlug demo-valencia
```

## Nota operativa

La tarea automatica no reemplaza la validacion del servidor nube. Si la API nube esta apagada o el puerto no responde, el worker queda instalado, pero los ciclos de sincronizacion reportaran error de conexion hasta que la nube vuelva a estar disponible.
