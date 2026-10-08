# Estándar tipográfico de la aplicación

Aplicar esta escala al crear pantallas y al rediseñar módulos. Las pantallas existentes se migran cuando se trabajen, evitando cambios globales que puedan romper tablas o formularios no revisados.

## Escala semántica

| Uso | Tamaño mínimo recomendado | Token Tailwind |
|---|---:|---|
| Ayudas, captions, metadatos | 13 px | `text-ui-caption` |
| Filas, encabezados de tabla y datos secundarios | 14 px | `text-ui-table` |
| Texto principal y párrafos | 15 px | `text-ui-body` |
| Campos y acciones principales | 16 px | `text-ui-control` |
| Títulos de sección | 18 px | `text-ui-section` |
| Título de página | 24 px | `text-ui-page` |

No usar tamaños de 10–11 px para texto funcional. Los tamaños inferiores a 13 px quedan reservados para elementos no esenciales que hayan sido revisados por legibilidad. Mantener una diferencia clara entre título, texto principal, datos tabulares y metadatos; evitar mezclar tamaños arbitrarios para el mismo uso.

## Aplicación por módulo

- Usar los tokens semánticos definidos en `frontend/src/styles/globals.css` en nuevos diseños y rediseños.
- Mantener el texto tabular compacto en 14 px como mínimo y revisar desbordes en móvil cuando se aumente el tamaño.
- Los campos y botones importantes deben conservar una lectura cómoda y un área táctil suficiente.
- Aplicar cambios a toda la app de forma progresiva, módulo por módulo, comprobando formularios, tablas y diálogos afectados.

`/sales` es la primera pantalla que usa esta escala.
