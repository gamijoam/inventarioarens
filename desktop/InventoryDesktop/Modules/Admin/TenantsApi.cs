using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading.Tasks;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop.Modules.Admin;

internal static class TenantsApi
{
    public static async Task<InventoryDesktop.Modules.Auth.TenantOption[]> GetMyTenantsAsync(ApiClient apiClient)
    {
        TenantListResponse? list = await apiClient.GetAsync<TenantListResponse>("tenants");
        return (list?.Data ?? []).ToArray();
    }
}

internal sealed record TenantListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<InventoryDesktop.Modules.Auth.TenantOption> Data);
