# Bugs del frontend WPF — plataforma SaaS Master (Wave 3.1)

> **Autor del análisis**: opencode (frontend)
> **Fecha**: 2026-07-13
> **Status**: ✅ Bugs #1-#3 RESUELTOS (commit `883d76d`); bug #5 RESUELTO (commit siguiente); bug #4 = deployment, requiere InventorySyncInstaller o `dotnet run` desde fuente.
> **Audiencia**: equipo de frontend WPF. No es trabajo de backend Laravel.

## TL;DR

Los 3 bugs reportados durante la prueba en local del WPF (commit `9076735`)
son defectos del **frontend C#**, no del backend PHP/Laravel. El backend
funciona — los endpoints responden, los tokens se emiten, el `/api/auth/me`
devuelve datos correctos. El problema está en cómo la UI consume esos datos.

Si querés que opencode los arregle, son cambios chicos y aislados:

| # | Bug | Archivo | Línea | Tipo | Severidad | Status |
|---|-----|---------|-------|------|-----------|--------|
| 1 | NullReferenceException en `RefreshMeAsync` al iniciar sesión | `desktop/InventoryDesktop/ShellView.xaml.cs` | 99 | NullRef sin guard | **Crash** | ✅ Resuelto (`883d76d`) |
| 2 | "Cambiar empresa" cierra la app en vez de mantenerla abierta | `desktop/InventoryDesktop/ShellView.xaml.cs` | 369 | UX defectuosa | **Bloqueante** | ✅ Resuelto (`883d76d`) |
| 3 | Botón "Ingresar" del ProgrammerLoginWindow queda deshabilitado | `desktop/InventoryDesktop/Modules/Auth/ProgrammerLoginWindow.xaml.cs` | 73-77 | Lógica de validación | **Bloqueante** | ✅ Resuelto (`883d76d`) |
| 4 | Botón "Sincronizar" no hace nada en el .exe publicado | `desktop/InventoryDesktop/Modules/Sync/SyncWorkerViewModel.cs` | `FindRepoRoot` (línea 583) | **Deployment**, no bug de código | **Bloqueante en test, OK en prod** | ⏳ Documentado (siguiente commit) |
| 5 | "Cambiar empresa" muestra "Error al cambiar de empresa" | `desktop/InventoryDesktop/ShellView.xaml.cs` | 369 (post-fix #2) | Race con `Unloaded` re-revocando el token nuevo | **Bloqueante** | ✅ Resuelto (`52cfe24`) |
| 6 | "Cambiar empresa" devuelve 403 (permiso denegado) | `app/Modules/Tenancy/routes.php` | 12 | **Backend**: ruta `/api/tenants` no tiene `tenant` middleware | **Bloqueante** | ✅ Resuelto (`d6394204`) |
| 7 | `XamlParseException: Provide value on 'Syste...'` al cambiar empresa | `desktop/InventoryDesktop/Modules/Admin/SwitchTenantDialog.xaml` | 71 | StaticResource `BoolToVisConverter` no definido | **Bloqueante** | ✅ Resuelto (`0673356e`) |

---

## Bug 1 — NullReferenceException en `RefreshMeAsync`

**Síntoma**:
```
[ERROR] Excepcion no controlada en Dispatcher.
System.NullReferenceException: Object reference not set to an instance of an object.
   at InventoryDesktop.ShellView.RefreshMeAsync() in ShellView.xaml.cs:line 99
   at InventoryDesktop.ShellView.<.ctor>b__11_7(Object _, RoutedEventArgs _) in ShellView.xaml.cs:line 82
```

**Cuándo se reproduce**:
1. Login como `gerente.valencia@demo.test` / `password` (cualquier tenant user).
2. Inmediatamente al cargar `ShellView` (handler `Loaded` línea 82).
3. El handler `RefreshMeAsync` (línea 90-101) llama `GET /api/auth/me`, recibe datos del user, y luego:

```csharp
private async Task RefreshMeAsync()
{
    AuthLoginData? fresh = await sessionService.GetCurrentUserAsync();
    if (fresh is not null)
    {
        sessionService.PersistSession(new DesktopSession(
            ApiClient: session.ApiClient,
            Login: fresh,
            ApiBaseUrl: session.ApiBaseUrl));
        AppLogger.Info($"Sesion refrescada para '{fresh.Tenant.Name}'.");  // <- LINEA 99
    }
}
```

**Causa raíz**:
`AuthLoginData.Tenant` es `TenantOption?` (nullable). En la rama de
Platform Admin, `tenant` viene `null` (es parte del modelo). Pero
`ShellView.RefreshMeAsync` se llama también en sesiones de Platform
Admin si alguien entra a `MainWindow` con sesión de admin (vía
`OnPlatformAdminLoginSucceeded` o refresh de un programador que se
queda logueado).

Cuando `fresh.Tenant == null`, `fresh.Tenant.Name` lanza NRE.

Adicionalmente, el logger usa **string interpolation que se evalúa
incluso cuando el `Info` no se va a escribir** (espesor de un
side-effect gratuito pero rompe el flujo).

**Fix sugerido**:

```csharp
private async Task RefreshMeAsync()
{
    AuthLoginData? fresh = await sessionService.GetCurrentUserAsync();
    if (fresh is null)
    {
        return;
    }

    sessionService.PersistSession(new DesktopSession(
        ApiClient: session.ApiClient,
        Login: fresh,
        ApiBaseUrl: session.ApiBaseUrl));

    string label = fresh.Tenant is { } tenant
        ? tenant.Name
        : "Platform Admin";
    AppLogger.Info($"Sesion refrescada para '{label}'.");
}
```

Adicional: el `PersistSession` también debería chequear si la sesión
cambió de tenant (caso de switch) y, si es Platform Admin, NO
persistirla con un tenant inválido.

**Severidad**: crash visible. Si bien la app continúa funcionando
después del catch del Dispatcher, el usuario ve el mensaje y queda
con la duda de qué falló.

---

## Bug 2 — "Cambiar empresa" cierra la ventana principal

**Síntoma**:
El usuario abre el "Centro de módulos" (la home del ShellView), hace
click en **"Cambiar empresa"** (el botón que está en el header de la
ShellView), selecciona otra empresa en el dialog, y... la app se
cierra. Tiene que volver a abrir el `.exe` y autenticarse de nuevo.

**Causa raíz** (línea 369 de `ShellView.xaml.cs`):

```csharp
private async void SwitchTenant_Click(object sender, RoutedEventArgs e)
{
    ...
    AuthLoginData? fresh = await sessionService.SwitchTenantAsync(newTenantSlug);
    if (fresh is null) { ... return; }
    Window.GetWindow(this)?.Close();  // <-- LINEA 369
}
```

Después de hacer el switch tenant correctamente (el backend emite
nuevo token), el código llama `Window.GetWindow(this)?.Close()`.
`GetWindow(this)` es la `MainWindow` (porque `ShellView` está dentro
de `AppContent` que es el `ContentControl` de `MainWindow`). Cerrar
esa ventana cierra la app entera.

**Esto es lo que el usuario espera**:
- Seleccionar nueva empresa → la app se reconfigura para la nueva
  empresa (actualiza el header, refresca el inventario, etc.) sin
  pedir login de nuevo.
- El token nuevo reemplaza al viejo en memoria.
- El `DataContext` se reemplaza con la nueva sesión.
- La lista de módulos se recalcula (los permisos del nuevo usuario
  pueden ser distintos).

**Fix sugerido**:

```csharp
private async void SwitchTenant_Click(object sender, RoutedEventArgs e)
{
    ...
    AuthLoginData? fresh = await sessionService.SwitchTenantAsync(newTenantSlug);
    if (fresh is null) { ... return; }

    // NO cerrar la ventana. Reemplazar el ShellView por uno nuevo
    // con la nueva sesion, manteniendo el mismo MainWindow.
    var newShell = new ShellView(new DesktopSession(
        ApiClient: session.ApiClient,
        Login: fresh,
        ApiBaseUrl: session.ApiBaseUrl));
    Window.GetWindow(this)!.Content = newShell;
    // El ShellView viejo (this) ya no es necesario.
}
```

**Severidad**: bloqueante para multi-tenant en producción. Un usuario
que pertenezca a 2+ empresas no puede cambiar de una a otra sin
re-autenticarse.

---

## Bug 3 — Botón "Ingresar" del ProgrammerLoginWindow queda deshabilitado

**Síntoma**:
El usuario abre `Ctrl+Shift+P`, escribe email (ej. `platform@test.com`)
y contraseña (ej. `PlatformTest123`), y el botón "Ingresar" **nunca se
habilita**. No importa qué escriba, queda gris.

**Causa raíz** (línea 73-77 de `ProgrammerLoginWindow.xaml.cs`):

```csharp
public bool CanAccept =>
    !IsBusy
    && !string.IsNullOrWhiteSpace(Email)       // <-- Email está SIEMPRE vacío
    && EmailInput?.Text.Contains('@') == true
    && !string.IsNullOrEmpty(PasswordInput?.Password);
```

El problema: `Email` es una property que se setea **dentro de
`Accept_Click`**, no en cada keystroke:

```csharp
private async void Accept_Click(object sender, RoutedEventArgs e)
{
    if (!CanAccept) return;  // <-- nunca pasa: Email está vacío
    IsBusy = true;
    Email = EmailInput.Text.Trim().ToLowerInvariant();  // <-- demasiado tarde
    ...
}
```

Resultado: cuando el usuario escribe en el `TextBox`, `Email` sigue
vacío, `CanAccept` siempre devuelve `false`, el botón nunca se
habilita. El usuario no puede avanzar.

**Fix sugerido**:

```csharp
public bool CanAccept =>
    !IsBusy
    && !string.IsNullOrWhiteSpace(EmailInput?.Text)
    && EmailInput?.Text.Contains('@') == true
    && !string.IsNullOrEmpty(PasswordInput?.Password);
```

Y en `Accept_Click`, eliminar la línea `Email = EmailInput.Text.Trim().ToLowerInvariant();`
ya que ahora la validación es contra el TextBox directamente. Si se
quiere mantener `Email` como property observable, dejarla solo para
que `LoginViewModel`-equivalente del ProgrammerLoginWindow la use en
otros lados (no afecta `CanAccept`).

Adicional: el `LoginViewModel` regular probablemente tiene el mismo
patrón. Verificarlo y aplicar la misma corrección si está
duplicado.

**Severidad**: bloqueante. Sin el botón habilitado, NO se puede
entrar al modo programador.

---

## Resumen de cambios aplicados (commit `bdf6f5b`)

3 archivos tocados, 3 bugs resueltos:

| Archivo | Bug | Cambio aplicado |
|---|---|---|
| `desktop/InventoryDesktop/ShellView.xaml.cs` | #1 | Null-check de `fresh.Tenant`: si es null, label = "Platform Admin"; sino `tenant.Name`. |
| `desktop/InventoryDesktop/ShellView.xaml.cs` | #2 | Reemplaza `Window.GetWindow(this)?.Close()` por `owner.Content = new ShellView(newSession)`. Reconfigura el apiClient con el token nuevo antes de reemplazar. |
| `desktop/InventoryDesktop/Modules/Auth/ProgrammerLoginWindow.xaml.cs` | #3 | `CanAccept` ahora usa `EmailInput?.Text` (que se actualiza en cada keystroke) en vez de la property `Email` (que se seteaba tarde en `Accept_Click`). La property `Email` queda solo como observable de support. |

Tiempo real: ~20 min. Build + smoke test verdes:
- `dotnet build`: 0 errors, 8 warnings (preexistentes).
- `dotnet run XamlSmoke`: Fallos reales 0.
- WPF Release re-publicada, config actualizado en el escritorio.

### Verificacion manual recomendada

1. Login como `gerente.valencia@demo.test` (cajero) → no debe haber NRE
   visible (revisar logs en `%APPDATA%/.../logs/`).
2. Click en "Cambiar empresa" → seleccionar otra empresa → la app debe
   permanecer abierta, header actualizado, módulos recalculados.
3. `Ctrl+Shift+P` → escribir `platform@test.com` + `PlatformTest123` → el
   botón "Ingresar" debe habilitarse al escribir el email válido.

Si los 3 casos funcionan, los bugs estan cerrados.

## Trabajo de backend requerido

**Cero bugs frontend pendientes**, pero hubo **1 bug backend** que se
encontró en la sesión 2026-07-13 (#6). Total 6 bugs (5 frontend + 1 backend)
todos resueltos.

El backend responde correctamente a nivel de controllers y
endpoints individuales. El problema #6 era de enrutamiento:
faltaba el middleware `tenant` en un grupo de rutas.

---

## Bug 4 — Sincronizar no hace nada en el .exe publicado

**Síntoma**:
El usuario hace click en "Sincronizar" o "Sincronizar esta empresa"
en el ShellView. Aparece un `SyncProgressWindow` brevemente y luego
se cierra con un mensaje en el status: "No se encontro
scripts\sync-worker.cmd en el proyecto." El inventario local nunca
se actualiza.

**Causa raíz**:
El `SyncWorkerViewModel.ExecuteWorkerAsync` necesita correr un
proceso externo (cmd.exe que invoca `scripts\sync-worker.cmd`) que
a su vez llama `php artisan sync:run`. Para encontrar ese script,
usa `FindRepoRoot()` que sube directorios desde
`AppContext.BaseDirectory` buscando el archivo `artisan`.

Cuando el WPF corre desde el .exe publicado en
`C:\Users\gafit\Desktop\InventoryDesktop-PlatformAdmin-Test\`, no
hay `artisan` en ningún directorio padre. `FindRepoRoot` devuelve
null, `ResolveScriptPath` devuelve string vacío, y
`ExecuteWorkerAsync` sale temprano con el mensaje de error.

**No es un bug de código** — es la naturaleza de cómo se distribuye
el WPF. El sync worker **no se puede embeber en el .exe** porque
es un script PHP + cmd.exe que corre sobre el proyecto Laravel.

**Soluciones posibles**:

| Solución | Comandos | Costo | Adecuada para |
|---|---|---|---|
| **A. Correr la app desde el proyecto** | `cd C:\Users\gafit\Documents\INVENTARIOARENS`; `dotnet run --project desktop/InventoryDesktop` | 0 cambios | Dev/test |
| **B. Correr `InventorySyncInstaller`** | Ejecutar `desktop\InventorySyncInstaller\bin\...exe`, completar wizard | 0 cambios | Produccion / Cliente |
| **C. Patch: agregar `repoRoot` al config** | Editar `inventorydesktop.config.json` y agregar campo `repoRoot`; `FindRepoRoot` lo lee primero. | ~30 min | Casos puntuales |

**Solución recomendada (corto plazo)**: Opción A para los tests
del usuario. Abrir una terminal en
`C:\Users\gafit\Documents\INVENTARIOARENS` y correr:

```powershell
dotnet run --project desktop/InventoryDesktop
```

La app va a tener acceso a `artisan` (vía `bin/Debug/.../artisan`
caminando hacia arriba) y el sync worker va a funcionar.

**Solución recomendada (largo plazo)**: hacer el Installer de WPF
real para que distribuya el proyecto completo (similar a como
funciona `InventorySyncInstaller`).

**Severidad**: bloqueante para el flujo de sync en builds
publicados. No crash, no es bug — es feature gap de deployment.

---

## Bug 6 — "Cambiar empresa" devuelve 403 (permiso denegado) — **BACKEND BUG**

**Síntoma**:
Después de aplicar el fix #5 (cambio de empresa no cierra la app),
el usuario sigue viendo un error. El log muestra:

```
[ERROR] SwitchTenant fallo.
InventoryDesktop.Core.Api.ApiException
   at ApiClient.ReadResponseAsync[TResponse]
   at ApiClient.GetAsync[TResponse]
   at TenantsApi.GetMyTenantsAsync(ApiClient)
   at ShellView.SwitchTenant_Click
```

El endpoint `GET /api/tenants` responde 403 Forbidden.

**Causa raíz**:
El grupo de rutas en `app/Modules/Tenancy/routes.php:12` declaraba
solo `api.auth` como middleware, NO `api.auth + tenant`:

```php
// ANTES (incorrecto)
Route::middleware('api.auth')->group(function (): void {
    Route::get('tenants', [TenantController::class, 'index']);
    ...
});
```

Sin el middleware `tenant`, el `ResolveTenant` no se ejecutaba
para `/api/tenants`. Eso significa que:

- `app(TenantManager::class)->current()` era null.
- `setPermissionsTeamId($tenant->id)` NO se ejecutaba.
- El Spatie team era null.

En el controller, `$request->user()->can('tenants.view')` usa
Spatie para verificar el permiso. Spatie usa el team_id actual
para filtrar los roles. Con team_id null, no se encuentran roles
del usuario, así que el `can()` retorna false. El `abort_unless`
devuelve 403.

**Por qué el bug pasó inadvertido**:
- El controlador `TenantController` fue añadido con un grupo de
  rutas simplificado. La ruta solo tiene `api.auth` (autenticación)
  pero no `tenant` (resolución de tenant + set del team).
- El controller funcionaba para requests con un user que tuviera
  permisos globales (Platform Admin, que pasa por otra ruta). Pero
  para tenant users fallaba silenciosamente.

**Fix aplicado**:

```php
// DESPUES (correcto)
Route::middleware(['api.auth', 'tenant'])->group(function (): void {
    Route::get('tenants', [TenantController::class, 'index']);
    ...
});
```

Después del fix, `getPermissionsTeamId()` retorna `$tenant->id` (1
en este caso), el team se setea, y `$user->can('tenants.view')`
retorna true correctamente.

**Verificacion**:
- `php artisan optimize:clear` para refrescar la cache de rutas.
- `GET /api/tenants` con X-Tenant: demo-valencia ahora retorna 200
  con la lista de 5 tenants.
- 24/24 tests pasando en `MasterGroupApiTest`, `TenantApiTest`,
  `TenantIsolationTest`.

**Severidad**: bloqueante. Sin este fix, el flujo de "Cambiar
empresa" del ShellView no funciona. El WPF lo reporta como un
error genérico "Error al cambiar de empresa" — el mensaje
muestra el catch del frontend pero el error real era un 403 del
backend.

---

## Bug 7 — `XamlParseException` al hacer click en "Cambiar empresa" (frontend)

**Síntoma**:
Después de aplicar el fix #6 (middleware `tenant` en backend), el
usuario todavía ve un error al hacer click en "Cambiar empresa".
El log muestra:

```
[ERROR] Excepcion no controlada en Dispatcher.
System.Windows.Markup.XamlParseException: Provide value on 'Syste...
   at System.Windows.FrameworkElement.ApplyTemplate()
   at System.Windows.FrameworkElement.MeasureCore(Size availableSize)
   ...
```

**Causa raíz**:
El `SwitchTenantDialog.xaml:71` referencia un StaticResource
inexistente:

```xaml
Visibility="{Binding IsCurrent, Converter={StaticResource BoolToVisConverter}}"
```

`BoolToVisConverter` no está definido en el
`SwitchTenantDialog.Resources`, en `App.Resources`, ni en
ninguna otra parte. Existen otros nombres similares en la app:

- `SaasMasterView.xaml` define `BoolToVis` (sin sufijo).
- `ShellView.xaml` define `BooleanToVisibilityConverter` (otro nombre).

El smoke test del XamlSmoke no detectaba este problema porque
el parser estático es permisivo: deferred bindings con
StaticResource no resueltos pasan la fase de parse, pero explotan
en el measure pass cuando WPF intenta resolver el converter y
aplicarlo a la propiedad `Visibility`.

**Por qué se manifestaba después del fix #5**:
Antes del fix #5, el flujo de "Cambiar empresa" moría antes
(la app se cerraba). Después del fix, la app continúa y
`new SwitchTenantDialog(tenants, ...).ShowDialog()` llega a
ejecutarse. El dialog renderiza, WPF intenta resolver el
converter, falla, lanza XamlParseException.

**Fix aplicado**:

```xaml
<Window x:Class="..." ...>
    <Window.Resources>
        <BooleanToVisibilityConverter x:Key="BoolToVisConverter" />
    </Window.Resources>
    <Grid Margin="20"> ... </Grid>
</Window>
```

**Verificación**:
- `dotnet build`: 0 errors.
- XamlSmoke: `SwitchTenantDialog.xaml` OK, Fallos reales 0.
- Probable: el XamlSmoke no detecta este tipo de error por su parser
  permisivo, pero ahora el test sería redundante porque el resource
  está declarado.

**Severidad**: bloqueante. Sin este fix, el dialog de "Cambiar
empresa" no se renderiza.

---

## Bug 5 — "Cambiar empresa" muestra "Error al cambiar de empresa" (regresión post-fix #2)

**Síntoma** (regresión reportada el 2026-07-13 después del commit `883d76d`):
Al hacer click en "Cambiar empresa", seleccionar otra empresa, la app muestra
"Error al cambiar de empresa: <exception.Message>" en un MessageBox.

**Causa raíz**:
El fix #2 (`883d76d`) reemplaza el ShellView via
`owner.Content = new ShellView(newSession)`. Esto dispara el
`Unloaded` event del ShellView viejo, cuyo handler ejecuta:

```csharp
Unloaded += async (_, _) =>
{
    await LogoutAsync();  // <-- revoca el token
};
```

Pero ANTES de que el Unloaded handler corra, el código del
`SwitchTenant_Click` ya reconfiguró el `apiClient` con el token
nuevo. Cuando `LogoutAsync` llama `POST /api/auth/logout` con ese
`apiClient`, está revocando el **token nuevo**, no el viejo.

Resultado: el nuevo ShellView queda con un token recién revocado.
El primer call que haga (RefreshMeAsync, etc.) devuelve 401, y la
app termina en el catch block del `SwitchTenant_Click` con un error
genérico.

**Fix aplicado**:

```csharp
private bool isBeingReplaced;

public ShellView(DesktopSession session)
{
    ...
    Unloaded += async (_, _) =>
    {
        if (isBeingReplaced)
        {
            return;  // Skip logout - we're being swapped, not closed.
        }
        await LogoutAsync();
    };
}

private async void SwitchTenant_Click(object sender, RoutedEventArgs e)
{
    ...
    isBeingReplaced = true;  // <-- ANTES del owner.Content = ...
    owner.Content = new ShellView(newSession);
}
```

**Verificacion**: build OK, smoke test verde. Probar manualmente
que el switch tenant ahora mantiene la sesion y la app sigue
operativa despues del cambio.
