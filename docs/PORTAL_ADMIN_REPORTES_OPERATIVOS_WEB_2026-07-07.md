# Portal administrativo - Reportes operativos

## Objetivo

Se agrego una primera base de reportes operativos en el portal web para que el administrador pueda revisar ventas POS, ordenes pendientes, pagos, cajas y productos mas vendidos desde una vista compacta.

Esta pantalla no reemplaza el modulo financiero completo. Su objetivo es dar una lectura rapida de operacion diaria por empresa.

## Implementacion realizada

- Se agrego la seccion **Reportes** al menu del portal administrativo.
- Se creo una vista de alta densidad con:
  - ventas POS cobradas;
  - ticket promedio;
  - ordenes POS pendientes;
  - cajas abiertas;
  - ultimas ordenes POS;
  - pagos agrupados por metodo;
  - productos mas vendidos;
  - sesiones de caja recientes.
- La vista respeta el selector de empresa del portal.
- El periodo usa el filtro global del portal: hoy, semana o mes.
- El modulo no modifica datos; solo consulta y resume informacion existente.

## Backend

Nuevo endpoint:

```txt
GET /api/admin-portal/operational-reports
```

Permisos aceptados:

- `reports.view`
- `finance_reports.view`
- `sales.view`
- `cash_register.view`

Parametros opcionales:

- `period=today|week|month`
- `date_from=YYYY-MM-DD`
- `date_to=YYYY-MM-DD`

## Datos devueltos

El endpoint agrupa informacion en estas secciones:

- `sales`: ventas confirmadas, POS pagado, ticket promedio y ordenes pendientes.
- `cash_register`: cajas abiertas/cerradas, esperado en caja y sesiones recientes.
- `payment_methods`: pagos capturados por metodo y moneda.
- `top_products`: productos mas vendidos por cantidad y monto.
- `recent_orders`: actividad POS reciente.

## Reglas de aislamiento

- Todas las consultas filtran por el tenant activo.
- Las uniones con ventas, pagos y cajas tambien validan el tenant para evitar mezclas entre empresas.
- El token de una empresa no puede consultar reportes de otra mediante `X-Tenant`.

## Interfaz

La pantalla usa el estandar compacto definido para el portal web:

- tablas densas;
- cards pequeñas;
- metricas resumidas;
- botones delgados;
- sin bloques decorativos grandes.

## Pruebas cubiertas

- El endpoint devuelve metricas de ventas, pagos, caja y productos.
- El endpoint no mezcla datos entre empresas.
- El endpoint exige permisos.
- La pagina `/admin` contiene la seccion y tablas del modulo de reportes.

## Proximas mejoras sugeridas

- Agregar filtros por sucursal, caja y cajero.
- Exportar reporte a CSV.
- Agregar comparativo contra periodo anterior.
- Llevar historial detallado de ventas a un modulo de reportes mas completo.
