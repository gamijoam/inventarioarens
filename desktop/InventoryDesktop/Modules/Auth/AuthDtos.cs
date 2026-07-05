using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.Auth;

public sealed record TenantLookupRequest(
    [property: JsonPropertyName("email")] string Email);

public sealed record LoginRequest(
    [property: JsonPropertyName("email")] string Email,
    [property: JsonPropertyName("password")] string Password,
    [property: JsonPropertyName("device_name")] string DeviceName);

public sealed record TenantOption(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("domain")] string? Domain = null);

public sealed record TenantLookupResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<TenantOption> Data);

public sealed record AuthUser(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("email")] string Email);

public sealed record LoginData(
    [property: JsonPropertyName("token")] string Token,
    [property: JsonPropertyName("token_type")] string TokenType,
    [property: JsonPropertyName("expires_at")] DateTimeOffset? ExpiresAt,
    [property: JsonPropertyName("user")] AuthUser User,
    [property: JsonPropertyName("tenant")] TenantOption Tenant,
    [property: JsonPropertyName("roles")] IReadOnlyList<string> Roles,
    [property: JsonPropertyName("permissions")] IReadOnlyList<string> Permissions);

public sealed record LoginResponse(
    [property: JsonPropertyName("data")] LoginData Data);
