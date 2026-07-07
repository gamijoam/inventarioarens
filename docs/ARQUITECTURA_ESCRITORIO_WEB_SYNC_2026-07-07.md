# Arquitectura Escritorio, Web y Sincronizacion

Fecha: 2026-07-07

## Decision

El sistema se divide en dos areas principales de trabajo:

- **Aplicacion de escritorio local**: operacion diaria del negocio.
- **Portal web administrativo**: control gerencial, configuracion y supervision.

Ambas areas se conectan mediante sincronizacion local-nube. La aplicacion de escritorio trabaja contra una base PostgreSQL local para mantener velocidad y continuidad operativa. El portal web trabaja contra la base de datos de la nube para ofrecer vision centralizada de empresas, sucursales y operaciones.

## Aplicacion de escritorio local

La aplicacion WPF es el cliente operativo principal para el local fisico.

Responsabilidades:

- POS y ventas rapidas.
- Apertura, movimientos y cierre de caja.
- Centro de inventario operativo.
- Entradas y salidas de producto.
- Seriales e IMEI.
- Clientes.
- Creditos y cuentas operativas futuras.
- Garantias y devoluciones futuras.
- Trabajo con PostgreSQL local.
- Operacion aun si internet falla.

Reglas:

- El operador no debe depender de internet para vender o consultar stock local.
- Las validaciones de stock, caja, permisos, seriales y tenant siguen pasando por Laravel local.
- La app de escritorio no debe conectarse directo a PostgreSQL nube.
- La experiencia debe priorizar velocidad, pocas ventanas innecesarias y acciones claras para cajeros y operadores.

## Portal web administrativo

El portal web es la herramienta de administracion y supervision para duenos, gerentes y administradores.

Responsabilidades:

- Dashboard gerencial.
- Metricas de ventas, caja, inventario y sincronizacion.
- Gestion de usuarios, roles, permisos y perfiles.
- Configuracion de productos, precios y listas.
- Proveedores.
- Compras y recepciones futuras.
- Reportes administrativos y financieros.
- Auditoria.
- Supervision de varias empresas o sucursales desde un solo acceso.

Reglas:

- El portal web consulta y modifica informacion en la nube mediante Laravel.
- El usuario administrativo puede cambiar de empresa desde el selector del portal.
- Los cambios administrativos importantes deben generar eventos de sincronizacion para que los locales reciban las actualizaciones.
- La interfaz del portal debe seguir la guia de alta densidad: compacta, clara y orientada a productividad.

## Sincronizacion local-nube

La sincronizacion es el puente entre la operacion local y la administracion en nube.

Flujos principales:

- **Local a nube**: ventas, pagos POS, caja, movimientos de inventario, cambios operativos autorizados.
- **Nube a local**: productos, precios, listas de precio, tasas, permisos, usuarios, proveedores, configuraciones y cambios administrativos.
- **Bidireccional controlado**: clientes, creditos y ciertos datos operativos cuando se definan reglas de conflicto.

Reglas:

- La sincronizacion debe ser por empresa/tenant y por nodo local configurado.
- Una computadora puede configurar una empresa y descargar su foto inicial.
- Si el mismo equipo abre otra empresa autorizada, debe preparar esa empresa con su propia sincronizacion inicial.
- La sincronizacion no debe bloquear que otra computadora trabaje en la misma empresa.
- Si dos computadoras trabajan en la misma empresa, ambas deben poder subir eventos y recibir cambios.
- Los cambios deben ser idempotentes para evitar duplicados.
- Los conflictos deben resolverse con reglas explicitas, por ejemplo prioridad administrativa o ultima escritura segun el modulo.

## Separacion recomendada de responsabilidades

La arquitectura debe evitar que un modulo haga trabajo que pertenece a otro.

Ejemplos:

- POS vende y cobra, pero el cierre pertenece al modulo Caja.
- Caja controla turnos, arqueos y cierres, pero no decide precios.
- Inventario controla stock, seriales y movimientos, pero no administra permisos.
- Web administra configuracion y reportes, pero no debe ser requisito para vender en el local.
- Sincronizacion transporta cambios, pero no inventa reglas de negocio; aplica eventos validados por Laravel.

## Beneficios

- El local puede operar rapido aunque internet falle.
- La gerencia puede ver la informacion desde cualquier lugar.
- Las sucursales pueden trabajar de forma independiente sin perder control central.
- El sistema puede crecer hacia pedidos, traslados, compras, reportes y auditoria sin romper la base actual.
- La nube se convierte en centro de control, no en cuello de botella para la venta diaria.

## Riesgos a controlar

- Conflictos cuando local y nube editan el mismo dato.
- Sincronizaciones incompletas si un nodo se configura mal.
- Cambios manuales directos en base de datos que no generen eventos.
- Exceso de herramientas administrativas en escritorio o exceso de operacion diaria en web.
- Falta de monitoreo del worker local.

## Recomendacion actual

La arquitectura es correcta para el objetivo del sistema. Se recomienda continuar con el **portal web administrativo** hasta cerrar las herramientas base de administracion que luego alimentaran a la app local:

1. Gestion web de compras y recepciones.
2. Reportes web de ventas, caja e inventario.
3. Auditoria web.
4. Gestion web de configuraciones sincronizables.

Luego se debe volver al escritorio para conectar esos flujos operativos con la experiencia local.
