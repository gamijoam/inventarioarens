# Diseño Pendiente — Login SaaS Master en WPF (para revisión)

> **Estado**: Borrador para auditoría. NO IMPLEMENTADO todavía.
> **Fecha**: 2026-07-12
> **Autor del análisis**: opencode (frontend)
> **Decisión**: pendiente del equipo dueño del SaaS Master.

---

## 1. Contexto

Se implementó el login de **Platform Admin** en WPF (commit pendiente) con la siguiente ruta feliz:

1. La persona abre la app WPF.
2. En el campo "Servidor de la API" escribe la URL del backend de la nube (p. ej. `https://app.miinventariofacil.com/api/`).
3. Marca el checkbox **"Modo programador (SaaS Master)"**.
4. Ingresa su correo + contraseña.
5. El backend emite un `AuthToken` con `tenant_id = NULL` y se abre el panel **SaaS Master**.

Funciona. Pero hay un problema de arquitectura que vale resolver antes de multiplicar este flujo:

### 1.1 Problema

- El campo "Servidor de la API" está visible para **todos** los usuarios que abren la app — incluidos cajeros, vendedores, almaceneros, etc., que normalmente ni deberían ver esa palanca.
- El campo arranca en blanco o con un valor por defecto incorrecto cada vez que se reinstala/reinicia.
- El Platform Admin **necesita sí o sí** estar en línea contra la nube para autenticarse. No hay flujo offline para SaaS Master.
- El Platform Admin en sí mismo está **duplicado** entre "el que se autenticó en la web" y "el que se autenticó en el desktop" — son sesiones diferentes con tokens diferentes. Si mañana se quiere que un Platform Admin creado en la web sea reconocido por la app local, hay que resolver eso (vía sync, idea aún no confirmada por el dueño del producto).

### 1.2 Riesgo concreto

**Tijereta visual innecesaria**: cualquier cajero podría ver (y eventualmente tipiar) la URL de la API. Eso es metadata de operación que no aporta valor al usuario final y abre la puerta a confusión ("¿esto lo lleno yo? ¿cada cuánto?"). En una app de POS que se entrega a clientes, no debería existir ese campo en el flujo por defecto.

---

## 2. Preguntas abiertas que el dueño del SaaS Master debe responder

> Estas preguntas NO se pueden resolver con código sin una decisión de producto. Documentadas para que el equipo las responda.

| # | Pregunta | Por qué importa |
|---|---|---|
| Q1 | ¿El Platform Admin debe poder autenticarse **offline** (sin internet) en el desktop, o es 100% online contra la nube siempre? | Define si necesitamos replicar credenciales vía sync (caro) o solo documentar "necesita internet". |
| Q2 | ¿"Modo programador" debe ser una **pantalla aparte** (lanzada con un flag/atajo) o estar embebida en el LoginView estándar? | Cambia el modelo de UX y de permisos — un cajero normal NUNCA debe ver este botón. |
| Q3 | ¿La URL de la API debe vivir **en código** (constante por build), **en archivo de config** (`appsettings.json` / `inventorydesktop.config`), o **en instalación por el `InventorySyncInstaller`**? | Define dónde está el archivo, quién lo escribe, y si el usuario puede cambiarlo. |
| Q4 | ¿El Platform Admin creado en la web debe "bajar" al desktop vía sync como los masters de catálogo, o vive solo en la nube y el desktop no lo conoce? | Define si hay que extender el motor de sync, añadir un nuevo event type, etc. |
| Q5 | ¿El panel SaaS Master es accesible desde CUALQUIER instalación de la app WPF, o solo desde una "edición programador" del instalador? | Afecta a la distribución y al `InventorySyncInstaller`. |

---

## 3. Alternativas de diseño (con sus trade-offs)

### Opción A — URL en archivo de configuración, sin campo visible

**Cómo funciona:**
- Al instalar (vía `InventorySyncInstaller`), se escribe un `inventorydesktop.config` en la carpeta del `.exe`:
  ```json
  { "apiBaseUrl": "https://app.miinventariofacil.com/api/" }
  ```
- El LoginView **NO muestra** ningún campo de URL.
- "Modo programador" sigue siendo un checkbox **pero solo aparece** si el `config` tiene `"allowProgrammerMode": true` (lo setea el installer; los tenants retail lo dejan en `false`).
- El campo de URL sigue ocultándose al cajero, pero el programador sí tiene cómo cambiarlo (leyendo/reescribiendo el config).

**Pros:**
- El usuario final (cajero) no ve metadata de operación.
- Config inmutable para él, editable para el técnico.
- Compatible con las 3 superficies (WPF, Configurador, futuro mobile).

**Contras:**
- Si el operador necesita cambiar el servidor (p. ej. cambiar de staging a prod), tiene que abrir un `.json`. Aceptable porque es operación de técnico.
- Hay que diseñar el schema de `inventorydesktop.config` (qué otras cosas van ahí: feature flags, environment, etc.).

**Esfuerzo:** medio. ~1 sprint entre config schema + leer config en `InventorySyncInstaller` + LoginView lo consume.

---

### Opción B — Panel programador en ventana aparte, lanzado por flag

**Cómo funciona:**
- El LoginView estándar tiene solo: email + contraseña + empresa.
- El checkbox "Modo programador" se oculta por defecto. Aparece solo si:
  - El archivo `inventorydesktop.config` tiene `allowProgrammerMode: true`, **o**
  - Se invoca la app con `--programmer` en los argumentos de línea de comando, **o**
  - Hay un atajo de teclado oculto (Ctrl+Shift+P) que lo revela.
- Al activar "Modo programador", se abre una **ventana aparte** (`ProgrammerLoginWindow`) con:
  - Email + contraseña (sin empresa, porque Platform Admin no tiene empresa).
  - URL editable solo si la clave `allowProgrammerMode: true` está puesta.
- Después del login de Platform Admin se abre el SaaS Master como ahora.

**Pros:**
- Separación física de los dos flujos. No hay un "campo más" en la pantalla que ve el cajero.
- El shortcut oculto evita que el cajero tropiece accidentalmente con la palanca.
- Permite, en el futuro, entregar builds distintas (WPF-Retail / WPF-Programmer) pero opcionalmente combinar ambas.

**Contras:**
- Más piezas en la UI (ventana separada, args CLI).
- El checkbox oculto puede frustrar al técnico si no sabe el atajo.

**Esfuerzo:** medio. ~1 sprint también.

---

### Opción C — Sync de "platform admin mirror" (lo que vos planteaste)

**Cómo funciona:**
- Cuando se crea un Platform Admin en la nube, se emite un sync event `platform_admin.upserted` (con un `admin_token_seed` cifrado o el equivalente que se considere aceptable en términos de seguridad).
- El `sync_inbox` del desktop local lo aplica y registra al admin en una tabla local `local_platform_admins` (separada del `auth_tokens` por seguridad — la nube nunca debería inyectar tokens en el desktop sin acción del usuario).
- Al autenticarse como Platform Admin en el desktop, se compara contra esta tabla **offline-first**; si no está, hace fallback a la nube.
- Si el desktop está offline, acepta credenciales cacheadas. Si está online, refresca y revalida contra la nube.

**Pros:**
- Coherente con la arquitectura de sync ya existente (patrón Local-First + Transactional Outbox).
- Funciona offline (útil para un programador en campo sin internet).
- Reutiliza el motor: `sync_outbox`, `sync_inbox`, dedup por `event_uuid`, etc.
- Mantiene la primitiva "Platform Admin existe en la nube" como la fuente de verdad — el desktop es solo un mirror autorizado.

**Contras:**
- Más superficie de seguridad. Hay que decidir **qué** se sincroniza (¿solo la identidad? ¿también el token? ¿o solo una "pista" de que ese correo es admin y el desktop le exige un password local?).
- Implica tocar el módulo Sync (nuevo event type, applier, smoke test del worker).
- Si el programador cambia su password en la nube, hay que invalidar el cache local.

**Esfuerzo:** alto. 2-3 sprints. Es una decisión de producto mayor — hay que validar primero las preguntas Q1 y Q4 con el equipo del SaaS Master.

---

### Opción D — Mantener el flujo actual (status quo)

**Cómo funciona:** La LoginView actual tal cual está. La URL es visible y editable para todos.

**Pros:**
- Ya funciona. Cero esfuerzo adicional.

**Contras:**
- Mismos problemas enumerados en §1.

**Esfuerzo:** cero.

---

## 4. Recomendación

- **Corto plazo (esta semana)**: cerrar la puerta a la confusión operativa haciendo lo mínimo — **ocultar la sección "Servidor de la API"** en LoginView para tenants donde `allowProgrammerMode` no esté habilitado. Esto se logra con un binding simple.
- **Mediano plazo (próximo sprint)**: ejecutar la **Opción B** (panel programador en ventana separada, lanzado por flag). Resuelve el problema sin sobrediseñar.
- **Largo plazo (cuando haya tracción en SaaS Master)**: ejecutar la **Opción C** (sync de Platform Admin mirror). Es la única que justifica la complejidad si realmente se necesita offline.

---

## 5. Lo que NO se hizo en este chat (a propósito)

- No se ocultó el campo de URL del LoginView (queda pendiente — Q1, Q2, Q3 abiertas).
- No se migró a un archivo `inventorydesktop.config` (Q3 abierta).
- No se extendió el motor de sync (Q1, Q4 abiertas).
- No se cambió el `InventorySyncInstaller` (Q5 abierta).

La pieza nueva que **sí** se implementó y debe quedar commiteada es **el endpoint `POST /api/auth/platform-login`** + el panel SaaS Master en WPF. Esos sí resuelven un problema concreto y testeable: permiten crear grupos/spinoffs/programadores desde el desktop ya autenticado.

---

## 6. Lista de archivos pendientes de decisión de UX

Estos archivos están en el commit actual y son **funcionales**, pero su UX queda bajo la decisión de las opciones A/B/C/D:

- `desktop/InventoryDesktop/Modules/Auth/LoginView.xaml` —> la sección "Servidor de la API" sigue visible para todos.
- `desktop/InventoryDesktop/Modules/Auth/LoginView.xaml.cs` —> autoload de URL persistida.
- `desktop/InventoryDesktop/Modules/Auth/LoginViewModel.cs` —> persistencia de URL al hacer login.

Si se decide la Opción A o B:
- `LoginView.xaml` debería tener una sección "Programmer Console" oculta detrás de atajo o flag.
- `LoginViewModel.IsPlatformAdminModeVisible` debería ligar a `config.allowProgrammerMode` en vez de a heurística de email.
- `MainWindow.xaml.cs` debería abrir `ProgrammerLoginWindow` (nueva ventana) en vez de mostrar el SaaS Master inline.

---

## 7. Para el equipo

Por favor:

1. **Resolver Q1, Q2, Q3** en una reunión de producto (no son decisiones técnicas, son de alcance).
2. Después de eso, podemos codear la opción elegida en este mismo branch.
3. Mientras tanto, **el commit actual** (commit "feat(tenancy): Platform Admin login + SaaS Master panel") es funcional, testeable, y back-deployable. No rompe nada existente (578 tests pasan + smoke test WPF verde).

Si se decide "no tocar la URL en absoluto", basta con revertir los 3 archivos `LoginView.*` del commit actual.
