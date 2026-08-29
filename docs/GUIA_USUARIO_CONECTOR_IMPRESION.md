# Guia de Usuario: Conector de Impresion

El Conector de Impresion permite que los tickets creados en la aplicacion online se impriman en la
impresora de una computadora Windows. La aplicacion funciona en segundo plano y no requiere abrir
PowerShell ni ejecutar comandos.

## Antes de comenzar

- Ten disponible la computadora Windows que estara junto a la impresora.
- Verifica que la impresora este instalada en Windows o conectada a la red.
- Asegura que la computadora tenga internet.
- Solicita al administrador acceso a la pantalla **Impresion** de la aplicacion.

## 1. Instalar el conector

1. Descarga `InventarioArens-Print-Connector-Setup-<version>.exe` desde el enlace entregado por
   soporte.
2. Abre el instalador y conserva las opciones recomendadas.
3. Al terminar, se abrira la ventana **Print Connector** y quedara disponible un acceso directo.

Tambien existe una version portable. Esa version no instala accesos directos, pero se usa igual
despues de abrirla.

## 2. Vincular la computadora

1. En la aplicacion online, abre **Impresion**.
2. En **Conectores locales**, selecciona **Generar codigo de vinculacion**.
3. Mantén abierta esa pantalla. El codigo se usa una sola vez y vence en 10 minutos.
4. En **Print Connector**, deja la URL predeterminada y escribe:
   - **Codigo de vinculacion**: el codigo mostrado en la aplicacion online.
   - **Nombre de esta caja**: un nombre facil de reconocer, por ejemplo `Caja Principal`.
5. Selecciona **Vincular con la nube**.
6. Espera a que el estado muestre **Conectado**.

Si el codigo vencio o ya fue utilizado, genera uno nuevo en **Impresion**.

## 3. Configurar la impresora online

Esta configuracion se realiza en la pantalla **Impresion** de la aplicacion online:

1. En **Estacion de impresion**, escribe el nombre de la estacion.
2. Selecciona el perfil del ticket, normalmente 58 mm u 80 mm.
3. Selecciona **Termica** o **Termica + digital**.
4. Selecciona la impresora:
   - **Impresora Windows (driver)**: escribe el nombre exacto con el que aparece instalada en
     Windows.
   - **Impresora de red (TCP 9100)**: escribe la IP de la impresora y deja el puerto `9100`, salvo
     que la impresora use otro puerto.
5. En el campo de conector, selecciona el conector que acabas de vincular.
6. Selecciona la sucursal o caja si corresponde y pulsa **Crear estacion** o **Guardar estacion**.

La estacion debe quedar activa y asociada al conector correcto. La configuracion de la impresora
no se hace dentro de la ventana del conector.

## 4. Uso diario

- Deja la computadora encendida y conectada a internet.
- El conector puede permanecer minimizado en la bandeja de Windows.
- Cerrar la ventana no detiene el conector: lo oculta en la bandeja.
- Para volver a abrirlo, selecciona el icono de la bandeja o abre **Print Connector** desde el menu
  Inicio.
- No selecciones **Salir** mientras necesites imprimir.
- Al pagar una venta POS, el ticket se enviara automaticamente a la impresora configurada.

## Estados de la ventana

| Estado           | Significado                                           | Accion                              |
| ---------------- | ----------------------------------------------------- | ----------------------------------- |
| Sin configurar   | La computadora aun no esta vinculada.                 | Generar un codigo y vincularla.     |
| Conectado        | El conector esta activo y consultando la nube.        | No requiere accion.                 |
| Detenido         | La vinculacion existe, pero el proceso esta detenido. | Seleccionar **Activar**.            |
| Revisar conexion | Hubo un problema de red o de configuracion.           | Seleccionar **Comprobar conexion**. |

## Problemas comunes

### El codigo no funciona

Genera otro codigo en **Impresion**. Los codigos son de un solo uso y duran 10 minutos.

### Aparece “Revisar conexion”

Verifica que la computadora tenga internet, que la URL sea la correcta y selecciona **Comprobar
conexion**. Para la instalacion principal, la URL es:

`https://app.miinventariofacil.com/api`

### El conector dice “Conectado”, pero no imprime

Revisa en **Impresion** que:

- la estacion este activa;
- la estacion tenga seleccionado este conector;
- el modo de salida incluya **Termica**;
- el nombre del driver Windows coincida exactamente, o la IP y el puerto TCP sean correctos;
- la impresora este encendida y tenga papel.

### Se cambio la computadora o la impresora

Para cambiar de computadora, instala el conector en la nueva PC y vincula un codigo nuevo. Para
cambiar de impresora, edita la estacion en **Impresion**. No compartas el codigo ni el token del
conector.

## Seguridad

El conector solo realiza conexiones salientes seguras hacia la nube. No abre puertos en la red local.
El codigo de vinculacion es temporal y el token de la instalacion no se muestra en la ventana.
