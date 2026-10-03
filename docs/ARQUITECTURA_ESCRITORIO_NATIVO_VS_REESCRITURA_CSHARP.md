# Análisis Técnico: Reescritura Nativa (C# / Go) vs Optimización de Experiencia de Escritorio

**Documento para el Equipo de Desarrollo y Dirección de Producto**  
**Fecha:** Octubre 2026  
**Proyecto:** INVENTARIOARENS / BalanzaPro Cloud & Desktop  

---

## 1. Resumen Ejecutivo y Veredicto

El equipo de desarrollo ha planteado la posibilidad de reescribir los módulos de la aplicación de escritorio en un lenguaje nativo como **C# (.NET / WPF)** o **Go**, argumentando motivos de **rendimiento y "sensación nativa"** (bordes de ventana, efectos visuales y velocidad percibida), manteniendo el backend en Laravel únicamente para el VPS.

### Veredicto Técnico:
**Reescribir los módulos de negocio en C# o Go para escritorio es un antipatrón de alto riesgo ("la trampa de las dos fuentes de verdad").**  
El proyecto ya contó anteriormente con un cliente WPF en C# (`desktop/InventoryDesktop.slnx`) y fue retirado en julio de 2026 debido a los graves problemas de mantenimiento dual y divergencia de reglas contables y de sincronización.

La sensación de "no nativo" o "lentitud visual" **no proviene del lenguaje de programación ni de la base de datos**, sino de comportamientos típicos de navegador web en el frontend (transiciones CSS de 300 ms, menús contextuales al dar clic derecho, selección de texto con el cursor y barras de scroll genéricas). 

La solución técnica correcta es **mantener el backend unificado (Laravel + FrankenPHP en Go)** y aplicar una **capa de optimización de escritorio nativo (Desktop Polish)** en el frontend y en el wrapper de Go/WebView2.

---

## 2. Implicaciones Críticas de Reescribir el Backend en C# / Go

### A. La Trampa de la "Doble Verdad" (Doble Mantenimiento Perpetuo)
INVENTARIOARENS cuenta con más de **40 módulos de negocio específicos para el mercado latinoamericano / venezolano**:
1. Moneda dual (USD base, VES operativo) con tipos de tasa (`BCV`, `PARALELO`, tienda) y snapshot histórico de tasa congelado en cada movimiento de pago.
2. Control de existencias con costo promedio móvil, kardex ponderado e impuestos combinados.
3. Reservas de inventario con expiración automática, seriales / IMEIs y transferencias entre sucursales.
4. Esquema de roles y permisos granulares por empresa (`spatie/laravel-permission` con multi-tenancy).

**Consecuencia directa:**  
Si se reescribe el escritorio en C# o Go, **cada nueva funcionalidad o cambio de regla fiscal tendrá que programarse dos veces** (una vez en PHP/Laravel para la nube y otra en C#/Go para local). Las discrepancias por redondeo decimal de punto flotante o diferencias sutiles de fechas entre C# y PHP serán inevitables.

### B. El Riesgo de Ruptura de Sincronización (Local-First Sync)
El motor de sincronización opera bajo el patrón **Local-First con Transactional Outbox/Inbox**:
- Un evento JSON emitido en local es procesado en el VPS por las mismas clases y validadores de Laravel.
- Si la lógica local se programa en C#, cualquier divergencia en cómo se crea o valida una venta, pago o movimiento de stock provocará rechazos o inconsistencias en la base de datos central de la nube.

### C. Costo de Oportunidad y Time-to-Market
Reescribir 40 módulos completos con sus pruebas unitarias, modelos relacionales y validaciones tomaría entre **6 y 12 meses de trabajo exclusivo**:
- Durante ese año el producto estará comercialmente paralizado, sin poder entregar valor a los clientes.
- La duplicación de errores aumentará la carga del equipo de soporte técnico.

---

## 3. Desmitificando el "Rendimiento" y la "Sensación Nativa"

| Percepción del Equipo | Causa Real Identificada | Solución Correcta (Sin reescribir) |
|---|---|---|
| *"No se siente nativo"* | Clic derecho muestra menú de navegador ("Inspeccionar", "Recargar"). Selección de texto azul en botones y tablas. | Deshabilitar `contextmenu`, zoom de navegador y aplicar `user-select: none;` global en la UI. |
| *"Efectos y transiciones"* | Animaciones CSS web de 200–300 ms (`fade-in`, deslizamientos, rebotes) en modales y menús. | Eliminar animaciones decorativas (`transition: none; duration: 0ms;`). La UI debe responder en 0 ms igual que un diálogo nativo de Windows. |
| *"Bordes del programa"* | Marco de ventana estándar de Windows sin integración con modo oscuro o estilo del software. | Activar atributos de ventana de Windows 11/10 (`DWMWA_USE_IMMERSIVE_DARK_MODE`) en el ejecutable Go/WebView2. |
| *"Velocidad de peticiones"* | Escaneos de tabla secuenciales en SQLite o inicio en frío de servidor web. | Utilizar **FrankenPHP** (Go + Caddy) precargado en memoria RAM + SQLite en modo WAL (`PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL;`). Respuestas locales en 2–5 ms. |

---

## 4. Hoja de Ruta: Cómo lograr una Experiencia 100% Nativa

Grandes aplicaciones líderes de la industria como **VS Code, Discord, Slack y Spotify** utilizan este mismo enfoque (núcleo web acelerado en WebView2/Chromium). Para alcanzar esa misma calidad en BalanzaPro / INVENTARIOARENS, se implementará la siguiente hoja de ruta:

### Fase 1: Ergonomía de Escritorio en Frontend (React / Tailwind)
- [ ] **Respuesta Instantánea (0 ms)**: Configurar todas las ventanas modales, diálogos de confirmación y dropdowns sin transiciones de desvanecimiento ni animaciones CSS lentas.
- [ ] **Bloqueo de Comportamientos de Navegador**:
  - Prevenir menú contextual del clic derecho (`onContextMenu={(e) => e.preventDefault()}`).
  - Desactivar atajos del navegador que recargan o abren herramientas (`F5`, `Ctrl+R`, `Ctrl+U`, `Ctrl+Shift+I`).
  - Desactivar zoom involuntario (`Ctrl + Scroll`, `Ctrl + + / -`).
  - Bloquear arrastre de imágenes y selección accidental de texto con el cursor (`user-select: none; -webkit-user-drag: none;`).
- [ ] **Navegación Fluida por Teclado**:
  - Atajos rápidos de teclado para POS y Administrativo (`F1` ayuda, `F2` buscar producto, `F4` cambiar lista de precios, `Enter` confirmar/pasar al siguiente campo, `Esc` cerrar modales).
- [ ] **Scrollbars Nativos Compactos**:
  - Estilizar barras de desplazamiento para que sean ultradelgadas, sin botones de flechas, similares a las de Windows 11.

### Fase 2: Shell de Ventana y Sistema Operativo (Go / WebView2)
- [ ] **Marco de Ventana Inmersivo (DWM)**:
  - Inyectar flags de Windows API en `tools/desktop-pos` y `tools/desktop-admin`:
    - `DWMWA_USE_IMMERSIVE_DARK_MODE` para borde y barra de título en color oscuro nativo.
    - Soporte nativo para Aero Snap de Windows (maximizar arrastrando al borde superior).
- [ ] **Icono y Nombre de Proceso Limpio**:
  - Asignación de icono incrustado en el `.exe` para la barra de tareas y el administrador de tareas de Windows.

### Fase 3: Aceleración del Motor Local (Go + FrankenPHP + SQLite)
- [ ] **FrankenPHP Workers en RAM**:
  - El servidor local corre precargado en memoria permanente (workers residentes), respondiendo en menos de 5 milisegundos por petición sin abrir procesos PHP separados.
- [ ] **Optimización de Almacenamiento SQLite**:
  - Activar `PRAGMA journal_mode = WAL` (Write-Ahead Logging) y `PRAGMA cache_size = -64000` (64 MB de caché en RAM para lectura inmediata de catálogo).
  - Índices compuestos en columnas críticas de búsqueda (`barcode`, `code`, `name`).

---

## 5. Conclusión

Mantener la **fuente única de verdad en Laravel** para la lógica comercial garantiza que una venta, un cálculo fiscal o una regla de inventario se compute con idéntica precisión en la nube y en la tienda física.

La mejora de la experiencia de usuario debe enfocarse en **la ergonomía de escritorio, la eliminación de latencias visuales en el frontend y el refinamiento de la ventana nativa**, obteniendo un resultado indistinguible de una aplicación en C# en una fracción mínima de tiempo y sin riesgo técnico para la empresa.
