# Guia UI de alta densidad para el portal administrativo

## Regla permanente

Esta guia queda como estandar obligatorio para todo el portal administrativo web de Mi Inventario Facil. Cualquier pantalla nueva o rediseño dentro de `/admin` debe partir de esta regla antes de agregar estilos propios.

## Objetivo

El portal administrativo de Mi Inventario Facil debe sentirse como una herramienta de trabajo diaria, no como una pagina comercial amplia. La prioridad es mostrar mas informacion util en menos espacio, con lectura clara y minimo scroll.

## Reglas base

- Texto general entre 12px y 14px.
- Encabezados compactos, sin hero gigante.
- Botones, inputs y selects con altura reducida.
- Paneles con padding minimo.
- Tablas densas, con filas bajas y celdas de 5px a 8px de padding.
- Separacion reducida entre columnas, cards y modulos.
- Navegacion lateral compacta.
- Acciones principales visibles sin ocupar franjas grandes.
- Formularios pensados para captura rapida, no para presentacion comercial.
- Mensajes de error claros en español y sin bloques permanentes enormes.

## Aplicacion actual

- Se redujo el tamano base del portal administrativo.
- Se compactaron encabezado, selector de empresa, periodo y botones.
- Se redujeron tarjetas de metricas, paneles y alertas.
- Se aumentaron las alturas utiles de tablas para mostrar mas registros.
- Se compactaron filtros, editor de inventario, listas de precio y usuarios/permisos.

## Criterio para futuras pantallas

Cuando se agregue una nueva pantalla administrativa, debe priorizar:

1. Barra superior compacta.
2. Filtros en una sola fila siempre que sea posible.
3. Tabla como elemento principal.
4. Formularios laterales o modales solo cuando aporten claridad.
5. Mensajes de error visibles, pero sin ocupar grandes bloques permanentes.

## Criterio de aceptacion visual

Antes de cerrar una pantalla administrativa:

- debe verse util al 100% de zoom del navegador;
- debe permitir leer datos principales sin bajar demasiado;
- debe mantener tablas y controles compactos;
- no debe sentirse como landing page;
- debe conservar consistencia con `resources/css/admin.css`.
