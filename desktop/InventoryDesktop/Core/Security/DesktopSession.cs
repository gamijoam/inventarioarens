using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop.Core.Security;

public sealed record DesktopSession(
    ApiClient ApiClient,
    LoginData Login,
    string ApiBaseUrl)
{
    public string UserName => Login.User.Name;

    public string TenantName => Login.Tenant.Name;

    public string TenantSlug => Login.Tenant.Slug;

    public bool HasPermission(string permission)
    {
        return Login.Permissions.Contains(permission, StringComparer.OrdinalIgnoreCase);
    }

    public bool HasAnyPermission(params string[] permissions)
    {
        return permissions.Any(HasPermission);
    }
}
