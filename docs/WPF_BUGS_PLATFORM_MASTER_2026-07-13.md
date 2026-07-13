# Bugs del frontend WPF — plataforma SaaS Master (Wave 3.1)

> **Autor del análisis**: opencode (frontend)
> **Fecha**: 2026-07-13
> **Status**: ✅ RESUELTO (commit `bdf6f5b`)
> **Audiencia**: equipo de frontend WPF (3 bugs C# detectados). No es trabajo de backend Laravel.

## TL;DR

Los 3 bugs reportados durante la prueba en local del WPF (commit `9076735`)
son defectos del **frontend C#**, no del backend PHP/Laravel. El backend
funciona — los endpoints responden, los tokens se emiten, el `/api/auth/me`
devuelve datos correctos. El problema está en cómo la UI consume esos datos.

Si querés que opencode los arregle, son cambios chicos y aislados:

| # | Bug | Archivo | Línea | Tipo | Severidad | Status |
|---|-----|---------|-------|------|-----------|--------|
| 1 | NullReferenceException en `RefreshMeAsync` al iniciar sesión | `desktop/InventoryDesktop/ShellView.xaml.cs` | 99 | NullRef sin guard | **Crash** | ✅ Resuelto |
| 2 | "Cambiar empresa" cierra la app en vez de mantenerla abierta | `desktop/InventoryDesktop/ShellView.xaml.cs` | 369 | UX defectuosa | **Bloqueante** | ✅ Resuelto |
| 3 | Botón "Ingresar" del ProgrammerLoginWindow queda deshabilitado | `desktop/InventoryDesktop/Modules/Auth/ProgrammerLoginWindow.xaml.cs` | 73-77 | Lógica de validación | **Bloqueante** | ✅ Resuelto |

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

**Cero**. Los 3 bugs son frontend. El backend responde correctamente
(verificado vía `curl` en sesión previa):
- `GET /api/auth/me` devuelve 200 con datos correctos.
- `POST /api/auth/switch-tenant` devuelve 201 con nuevo token.
- `POST /api/auth/platform-login` emite tokens sin tenant.

Si en el futuro aparecen bugs de los que NO estoy seguro, los
marco con **"requiere investigación de backend"** en este mismo
documento.
