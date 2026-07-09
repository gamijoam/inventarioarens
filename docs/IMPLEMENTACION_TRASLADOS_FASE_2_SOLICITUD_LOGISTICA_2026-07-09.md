# Implementacion Traslados Fase 2 - Solicitud Logistica

Fecha: 2026-07-09

## Resumen

Se implemento la segunda fase backend del modulo de traslados logisticos. Ahora la API permite crear un traslado en modo logistico usando `validation_mode = logistics`.

Este modo no mueve inventario inmediatamente. Primero crea una solicitud con guia y checklist de preparacion pendiente. Esto permite que mas adelante el preparador marque lo cargado, reporte diferencias y el receptor valide lo recibido antes de completar el traslado.

## API Actualizada

Endpoint:

```txt
POST /api/inventory-transfers
```

Campo nuevo soportado:

```json
{
  "validation_mode": "logistics"
}
```

Valores permitidos:

- `simple`: comportamiento tradicional. Mueve stock de origen a destino y deja el traslado completado.
- `logistics`: crea solicitud, guia y checklist pendiente sin mover stock.

## Comportamiento Del Modo Logistico

Cuando se crea un traslado logistico:

1. Se valida que origen, destino, producto y seriales pertenezcan a la empresa activa.
2. Se valida que los seriales/IMEI esten disponibles en el almacen origen.
3. Se crea el traslado en estado `requested`.
4. Se genera una guia `GUIA-000001`, `GUIA-000002`, etc.
5. Se crea un checklist de preparacion en estado `pending`.
6. Cada item queda con:
   - cantidad solicitada;
   - cantidad preparada en cero;
   - cantidad recibida en cero;
   - diferencia en cero.
7. No se crean movimientos `transfer_out` ni `transfer_in`.
8. El stock del almacen origen permanece igual hasta que una fase posterior prepare o despache el traslado.

## Diferencia Contra Modo Simple

Modo simple:

- pensado para traslados rapidos internos;
- mueve stock inmediatamente;
- deja guia y traslado completados.

Modo logistico:

- pensado para traslados con validadores;
- genera guia operativa;
- deja checklist pendiente;
- no mueve stock todavia;
- prepara la base para carga, despacho, recepcion y diferencias.

## Pruebas Ejecutadas

Suite especifica:

```powershell
& 'C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe' artisan test tests/Feature/InventoryTransfers/InventoryTransferApiTest.php
```

Resultado:

- 7 pruebas pasadas.
- 54 aserciones.
- Validado en PostgreSQL local de pruebas.

## Siguiente Fase Recomendada

Continuar con Fase 3 backend:

- Endpoint para preparar traslado.
- Permitir marcar cantidades cargadas.
- Permitir marcar seriales/IMEI preparados.
- Registrar diferencias y motivo obligatorio cuando no coincide.
- Pasar el traslado de `requested` a `prepared` o `prepared_with_differences`.
- Definir si al preparar se reserva stock o si se reserva al despachar.

