# MCP de diagnostico BalanzaPro

El servidor `balanzapro-diagnostics` usa Laravel MCP. Su endpoint remoto es
`https://app.balanzapro.com/mcp` y no tiene acceso al MCP administrativo del VPS.

## Acceso desde ChatGPT

El endpoint usa OAuth 2.1 con PKCE y registro dinamico de cliente. Al conectarlo
en ChatGPT, el navegador abre BalanzaPro para iniciar sesion. Solo
`admin@balanzapro.com` puede autorizarlo y ChatGPT recibe un token OAuth revocable,
nunca la contrasena.

En ChatGPT: Plugins > Add custom MCP server > URL
`https://app.balanzapro.com/mcp` > OAuth.

## Herramienta inicial

`system-health` devuelve un estado seguro de la aplicacion y la conexion a la
base de datos. No devuelve secretos, configuracion, datos de empresas ni ejecuta
acciones mutables.

## Uso local

Desde una copia de desarrollo del proyecto:

```bash
php artisan mcp:start balanzapro-diagnostics
```

Configurar el cliente MCP para ejecutar ese comando desde la raiz del proyecto.

## Laravel Boost

Boost esta instalado como dependencia de desarrollo. No se configura ni ejecuta
contra este VPS productivo: sus herramientas incluyen consultas a base de datos
y ejecucion de codigo. Debe habilitarse solamente en una copia local de desarrollo
con `APP_ENV=local`.

## Proxima fase

Antes de publicar un servidor MCP remoto se debe definir autenticacion OAuth,
una identidad tecnica de solo lectura, limite de solicitudes y auditoria de cada
invocacion. Las herramientas de negocio deben usar servicios Laravel y nunca SQL
libre ni comandos del sistema.
