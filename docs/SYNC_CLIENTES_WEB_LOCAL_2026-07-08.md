# Sincronizacion de clientes web hacia locales

Fecha: 2026-07-08

## Contexto

Se detecto que un cliente creado desde el portal web podia no aparecer en una computadora local aunque el worker estuviera corriendo. El flujo de aplicacion local ya soportaba `customer.created`, pero la entrega desde nube tenia un punto debil cuando una empresa tenia mas de un nodo local activo.

## Causa

Los cambios administrativos creados desde la nube se registraban como un evento general de empresa. Si habia varias computadoras sincronizando la misma empresa, la primera que bajaba y confirmaba el evento podia dejarlo como procesado para toda la empresa. Eso hacia que otra computadora local ya no tuviera ese evento pendiente.

## Ajuste implementado

Los eventos administrativos creados desde la web, como clientes, productos, precios y listas, ahora se registran de forma independiente para cada nodo local activo:

- cada computadora local recibe su propio evento pendiente;
- confirmar el evento en una computadora no elimina el pendiente de otra;
- si todavia no existe ningun nodo local activo, se conserva el comportamiento anterior y queda un evento general para la empresa;
- los nodos tipo `cloud` no reciben estos eventos porque representan el servidor, no una instalacion local.

## Impacto en clientes

Cuando se crea, edita o desactiva un cliente desde la web:

1. El portal guarda el cliente en la base de datos de nube.
2. Se crea un evento `customer.created` o `customer.updated`.
3. El evento queda en `sync_outbox` una vez por cada computadora local activa.
4. Cada worker local baja su evento, lo aplica en `customers` local y lo confirma.

## Mejora de diagnostico

El worker continuo ahora muestra tambien los eventos ignorados:

```text
Subidos: 0 | Bajados: 1 | Aplicados: 1 | Ignorados: 0 | Fallos: 0
```

Esto evita confusiones cuando un evento baja pero no cambia datos porque fue ignorado por tipo no soportado.

## Prueba especifica

Se agregaron pruebas para validar:

- un `customer.created` recibido desde nube se aplica localmente;
- un cambio administrativo se entrega de forma separada a dos nodos locales activos;
- cuando un nodo confirma su evento, el evento del otro nodo sigue pendiente.

