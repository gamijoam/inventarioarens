# Sincronizacion de clientes escritorio-nube

## Objetivo

Documentar como deben viajar los cambios de clientes entre la aplicacion local de escritorio y la nube.

## Flujo esperado

1. El usuario crea, edita o desactiva un cliente en escritorio.
2. El backend local guarda el cambio en PostgreSQL local.
3. El backend local registra el evento de sincronizacion correspondiente.
4. El worker sube el evento a la nube.
5. La nube aplica el cambio respetando la empresa y registra el evento recibido.

El flujo inverso tambien debe funcionar:

1. Un administrador crea, edita o desactiva un cliente desde el portal web.
2. La nube registra el evento para las instalaciones locales de esa empresa.
3. El worker local descarga el evento.
4. El backend local aplica el cambio.
5. El escritorio muestra el cliente actualizado al refrescar.

## Reglas

- La sincronizacion se aplica por empresa, no debe mezclar clientes entre tenants.
- La desactivacion es logica; no debe borrar ventas, pagos ni historial.
- El consumidor final debe mantenerse disponible como cliente generico protegido.
- Si una computadora abre otra empresa, debe completar la sincronizacion inicial de esa empresa antes de operar sus datos.

## Pruebas recomendadas

- Crear `Cliente Sync Local` desde escritorio y confirmar que aparece en la base de datos de la nube.
- Editar el telefono o correo del cliente en la nube y confirmar que baja al escritorio.
- Desactivar un cliente desde escritorio y confirmar que queda inactivo en la nube.
- Crear dos clientes con datos diferentes en empresas distintas y confirmar aislamiento total.

## Pendiente

- Agregar prueba automatizada de ida y vuelta para clientes en el worker de sincronizacion.
- Mostrar en el modulo Clientes un aviso cuando existan eventos locales pendientes.
- Agregar administracion web de clientes con historial, saldos y segmentacion.
