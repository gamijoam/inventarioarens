# Laboratorio E2E de sincronizacion

Este laboratorio valida un recorrido real entre dos instalaciones locales aisladas y la nube:

1. cada nodo canjea su propio codigo temporal;
2. ambos descargan la foto inicial del mismo tenant de laboratorio;
3. el nodo A crea un cliente y emite un evento en outbox;
4. el nodo B queda desconectado de forma intencional;
5. el nodo B vuelve, recibe el evento, lo aplica y lo confirma;
6. se repite el ciclo para comprobar que el cliente no se duplica;
7. el nodo A registra una venta POS parcial a credito y luego cobra la CxC;
8. el nodo B recupera la venta, el descuento de stock y el cobro sin duplicarlos.

No usa SSH ni acceso directo a la base cloud. Los tokens solo viven en memoria durante la prueba.

## Preparacion segura

Usa una empresa dedicada a pruebas de sincronizacion. No uses una empresa operativa: la prueba crea dos nodos cloud, dos tokens de sincronizacion y un cliente identificable `E2E-...`.

Desde la organizacion de pruebas, el Owner debe generar **dos codigos de vinculacion**, ambos para la misma empresa y usuario. Cada codigo se consume una sola vez y vence segun el tiempo elegido.

Antes de ejecutar el script, prepara **esa misma empresa de laboratorio en la nube** con el
catalogo POS minimo. El comando se ejecuta en el VPS y no debe usarse sobre una empresa operativa:

```bash
cd /opt/inventarioarens-cloud
php artisan sync:lab:prepare-pos-credit <slug-empresa-lab> <marca>
```

La `<marca>` debe ser la misma que se pasara como `-Marker` al script. El comando crea un producto
de prueba, stock inicial de cinco unidades, cliente, caja, turno y una tasa USD/VES activa. La prueba
espera terminar con stock tres, una venta POS y una CxC pagada.

## Ejecutar en Windows

Desde la raiz del repositorio:

```powershell
.\scripts\sync-e2e-lab.ps1 `
  -PairingCodeNodeA 'ARNS-...' `
  -PairingCodeNodeB 'ARNS-...' `
  -CloudApiUrl 'https://app.miinventariofacil.com/api' `
  -Marker 'e2e-pos-001' `
  -KeepArtifacts
```

Las bases temporales de ambos nodos quedan bajo `storage/app/sync-e2e-lab/<marca-de-corrida>/`. Estan ignoradas por Git. Omite `-KeepArtifacts` si solo quieres el resultado y no necesitas inspeccionar los SQLite ni los logs.

La prueba se detiene ante cualquier error: codigo vencido, tenant distinto, snapshot incompleto, evento no aplicado o cliente duplicado.

## Cobertura actual

La prueba E2E ejercita autenticacion de nodos, snapshot inicial, outbox, push, pull, inbox, ACK,
recuperacion tras desconexion e idempotencia con un evento de cliente. Despues ejecuta una venta
POS real a credito, su descuento de inventario, la CxC y el cobro manual posterior.

La recuperacion financiera POS se protege adicionalmente con la prueba automatizada `tests/Feature/Sync/PosOrderStockSyncTest.php`: una venta a credito y su cobro posterior pueden llegar repetidos tras una desconexion sin duplicar salida de stock, cuenta por cobrar ni pago. El transporte conserva el codigo estable del nodo de origen, no el ID interno de otra instalacion.

La misma prueba cubre la colision de ultima unidad: si dos nodos desconectados venden la misma unidad, la primera venta aplicada consume el saldo y la segunda queda en `sync_inbox` como `failed`, con un mensaje de conflicto. No se recorta el stock a cero ni se crea una segunda venta parcial. Para IMEI/seriales, el segundo evento tambien falla si el equipo ya no esta disponible. Es una cola de resolucion consciente: el tecnico puede revisar el evento fallido, corregir stock o serial y reintentarlo desde el Centro tecnico.

Los escenarios de IMEI, imagenes y conflictos de stock se mantienen cubiertos en Feature tests y en el laboratorio de carga. Se agregaran como recorridos E2E independientes para no convertir una prueba diagnostica de sincronizacion en una carga pesada sobre la nube.

La regresion de devoluciones serializadas vive en `tests/Feature/Sync/SalesReturnSyncTest.php`. Simula una venta POS con IMEI, recibe dos veces la misma devolucion procesada y verifica que el equipo vuelve a estar disponible, el stock se restaura una sola vez y se conserva un unico movimiento de Kardex. El evento `sales_return.updated` se emite en cada transicion relevante: solicitada, aprobada, rechazada, cancelada o procesada.
