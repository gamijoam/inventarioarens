# Worker de sincronizacion en Windows

## Por que aparecio el error del log

El worker de sincronizacion estaba activo y escribiendo en:

```text
storage/logs/sync-worker-demo-valencia.log
```

Al mismo tiempo, la pantalla de sincronizacion intento leer ese mismo archivo. Windows puede bloquear temporalmente archivos que otro proceso esta usando. La sincronizacion no fallo por ese mensaje; fallo la lectura visual del log en la app.

## Correccion aplicada

La app de escritorio ahora lee el log con acceso compartido. Si el archivo esta ocupado justo en ese instante, la pantalla muestra un mensaje controlado en vez de cerrar la aplicacion.

## Como debe funcionar para el usuario final

El usuario no deberia abrir el worker manualmente ni ejecutar comandos.

El flujo recomendado es:

1. El tecnico abre el Configurador del Sistema.
2. Ingresa URL de la nube, correo y contrasena del administrador.
3. Selecciona la empresa.
4. El configurador:
   - prepara la base local;
   - guarda el token de sincronizacion;
   - descarga la informacion inicial;
   - activa el worker automatico.
5. El sistema principal muestra solo un semaforo:
   - sincronizado;
   - pendiente;
   - error.

## Como deberia activarse en Windows

Fase actual:

- El configurador puede iniciar el worker en segundo plano mediante `scripts/sync-worker.cmd`.
- El worker queda vivo mientras Windows mantenga ese proceso activo.

Fase recomendada para instalacion real:

- Crear una opcion en el Configurador llamada `Instalar sincronizacion automatica`.
- Esa opcion debe registrar el worker como una tarea de Windows o servicio local.
- Al encender la computadora, Windows inicia el worker sin que el usuario abra la app principal.

## Recomendacion

Para un sistema comercial, lo mejor es que el worker sea gestionado por el Configurador, no por el POS ni por el Centro de Inventario.

El programa principal solo debe mostrar el estado de sincronizacion. El Configurador debe encargarse de instalar, iniciar, detener o reparar el worker.
