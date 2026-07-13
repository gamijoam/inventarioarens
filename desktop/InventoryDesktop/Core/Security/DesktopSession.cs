using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop.Core.Security;

public sealed record DesktopSession(
    ApiClient ApiClient,
    LoginData Login,
    string ApiBaseUrl)
{
    public string UserName => Login.User.Name;

    public string? TenantName => Login.Tenant?.Name;

    public string? TenantSlug => Login.Tenant?.Slug;

    public long UserId => Login.User.Id;

    public string DisplayName => Login.User.Name;

    public DateTimeOffset? ExpiresAt => Login.ExpiresAt;

    public IReadOnlyList<string> Roles => Login.Roles;

    public IReadOnlyList<string> Permissions => Login.Permissions;

    public bool IsPlatformAdmin => Login.User.IsPlatformAdmin;

    public bool HasTenant => Login.Tenant is not null;

    public bool HasPermission(string permission)
    {
        return Login.Permissions.Contains(permission, StringComparer.OrdinalIgnoreCase);
    }

    public bool HasAnyPermission(params string[] permissions)
    {
        return permissions.Any(HasPermission);
    }

    public PersistedSession ToPersistedSession()
    {
        return new PersistedSession(
            Login.Token,
            TenantSlug ?? string.Empty,
            ApiBaseUrl,
            ExpiresAt,
            UserId,
            DisplayName);
    }

    public static DesktopSession FromPersisted(PersistedSession persisted, LoginData freshLoginData, ApiClient apiClient)
    {
        _ = persisted;
        return new DesktopSession(apiClient, freshLoginData, freshLoginData.Tenant?.Slug ?? string.Empty);
    }
}
