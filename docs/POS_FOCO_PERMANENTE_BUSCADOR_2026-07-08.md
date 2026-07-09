# POS con buscador siempre listo

## Resumen

El punto de venta debe comportarse como una caja real: al abrir el modulo, el cajero debe poder escanear un SKU, codigo, serial o IMEI sin hacer clic manualmente en el buscador.

## Regla de operacion

- Al entrar al POS, el buscador principal toma foco automaticamente.
- Despues de agregar productos, cerrar modales, usar botones o limpiar el carrito, el foco vuelve al buscador.
- Si el foco queda sobre un boton dentro del POS y el lector envia letras o numeros, el POS redirige esa entrada al buscador.
- Si el lector envia Enter y ya hay texto en el buscador, el POS ejecuta la busqueda e intenta agregar la coincidencia exacta.
- El foco no se roba cuando el usuario esta escribiendo en otro campo editable o usando un selector.

## Objetivo

Reducir friccion en caja. El cajero debe poder pistolear varios productos o IMEI seguidos sin tocar el mouse entre operaciones.
