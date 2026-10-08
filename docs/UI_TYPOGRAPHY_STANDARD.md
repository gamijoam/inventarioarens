# Estándar tipográfico de la aplicación

La escala se aplica globalmente a la interfaz de BalanzaPro. Los tamaños comunes de Tailwind se alinean a estos niveles; los tamaños funcionales menores de 13 px fueron migrados al nivel caption.

## Escala semántica

| Uso | Tamaño | Token semántico | Utility global compatible |
|---|---:|---|---|
| Ayudas, captions, metadatos y etiquetas | 13 px | `text-ui-caption` | `text-xs` |
| Filas, encabezados de tabla y datos secundarios | 14 px | `text-ui-table` | `text-sm` |
| Texto principal y párrafos | 15 px | `text-ui-body` | `text-base` |
| Campos y acciones principales | 16 px | `text-ui-control` | `text-lg` |
| Títulos de sección | 18 px | `text-ui-section` | `text-xl` |
| Título de página | 24 px | `text-ui-page` | `text-2xl` |

El cuerpo de la app usa 15 px como base. Los alias y los tokens se definen en `frontend/src/styles/globals.css`; `text-xs` queda en 13 px y no se usan clases de 8–12 px para texto funcional.

## Reglas de uso

- Elegir el tamaño por función (caption, tabla, cuerpo, control o título), no por preferencia aislada dentro de cada componente.
- Usar tokens semánticos en pantallas nuevas y rediseños; los alias globales mantienen consistentes las pantallas existentes.
- En componentes que combinan clases con `cn()`/`tailwind-merge`, usar `text-[length:var(--text-ui-...)]` para que el tamaño semántico no se confunda con una clase de color.
- Mantener alto contraste, interlineado legible y áreas táctiles adecuadas; revisar tablas y formularios al ajustar densidad.
- Evitar volver a introducir tamaños arbitrarios menores de 13 px. Las excepciones visuales (por ejemplo, números decorativos) deben ser revisadas explícitamente.

La escala se aplica a toda la interfaz de BalanzaPro y debe mantenerse en cada nuevo módulo o rediseño.
