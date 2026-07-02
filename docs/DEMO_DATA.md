# Datos demo

Este documento explica como cargar datos persistentes para revisar la base local desde HeidiSQL.

## Base objetivo

Los datos demo se cargan en la base local:

```txt
inventory_arens
```

No dependen de la base de pruebas:

```txt
inventory_arens_testing
```

## Comando

Con Docker levantado, ejecutar:

```txt
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan db:seed --class=DemoDataSeeder
```

## Que crea

- Empresas demo:
  - `arens-demo-caracas`
  - `arens-demo-valencia`
- Usuarios demo:
  - `cajero.caracas@demo.test`
  - `gerente.caracas@demo.test`
  - `cajero.valencia@demo.test`
  - `gerente.valencia@demo.test`
- Sucursales y almacenes por empresa.
- Tasas `BCV` y `PARALELO`.
- Productos:
  - `Samsung A06 128GB`, serializado por IMEI.
  - `Audifonos Bluetooth`, controlado por cantidad.
- Stock inicial.
- IMEIs demo en `product_units`.
- Cajas abiertas.
- Venta POS pagada.
- Venta POS con financiamiento externo pendiente.
- Movimientos de inventario, caja y auditoria generados por servicios reales.

## Reglas

- El seeder es idempotente: se puede ejecutar mas de una vez sin duplicar los datos demo principales.
- Los datos demo son para ambiente local o demostracion.
- Las pruebas automatizadas no dejan datos persistentes porque usan `RefreshDatabase`.
