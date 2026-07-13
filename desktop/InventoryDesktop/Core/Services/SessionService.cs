using System.Net.Http;
using System.Text.Json;
using System.Text.Json.Serialization;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop.Core.Services;

public sealed class SessionService
{
    private readonly ApiClient apiClient;
    private readonly TokenVault vault;

    public SessionService(ApiClient apiClient, TokenVault vault)
    {
        this.apiClient = apiClient;
        this.vault = vault;
    }

    public async Task<LoginData?> GetCurrentUserAsync(CancellationToken cancellationToken = default)
    {
        try
        {
            CurrentSessionResponse response = await apiClient.GetAsync<CurrentSessionResponse>("auth/me", cancellationToken);
            return response.Data;
        }
        catch (ApiException)
        {
            return null;
        }
    }

    public async Task<LoginData?> SwitchTenantAsync(string tenantSlug, string? deviceName = null, CancellationToken cancellationToken = default)
    {
        try
        {
            SwitchTenantRequest request = new(tenantSlug, deviceName ?? Environment.MachineName);
            LoginResponse response = await apiClient.PostAsync<SwitchTenantRequest, LoginResponse>("auth/switch-tenant", request, cancellationToken);
            return response.Data;
        }
        catch (ApiException ex)
        {
            AppLogger.Error($"SwitchTenantAsync falló: {ex.Message}", ex);
            return null;
        }
    }

    public async Task<bool> LogoutAsync(CancellationToken cancellationToken = default)
    {
        try
        {
            await apiClient.PostNoPayloadAsync<LogoutResponse>("auth/logout", cancellationToken);
            return true;
        }
        catch (ApiException ex)
        {
            AppLogger.Error($"LogoutAsync falló: {ex.Message}", ex);
            return false;
        }
    }

    public void PersistSession(DesktopSession session)
    {
        vault.SaveSession(session.ToPersistedSession());
    }

    public PersistedSession? LoadPersistedSession()
    {
        return vault.ReadSession();
    }

    public void ClearSession()
    {
        vault.Clear();
    }
}

public sealed record LogoutResponse(
    [property: JsonPropertyName("revoked")] bool Revoked);
