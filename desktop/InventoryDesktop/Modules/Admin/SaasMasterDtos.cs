using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.Admin;

public sealed record GroupOwnerPayload(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("email")] string Email,
    [property: JsonPropertyName("password")] string? Password = null);

public sealed record BranchPayload(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code);

public sealed record WarehousePayload(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code);

public sealed record ExchangeRateTypePayload(
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("name")] string Name);

public sealed record CreateGroupRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("group_owner")] GroupOwnerPayload GroupOwner,
    [property: JsonPropertyName("plan")] string? Plan = "enterprise",
    [property: JsonPropertyName("domain")] string? Domain = null,
    [property: JsonPropertyName("branch")] BranchPayload? Branch = null,
    [property: JsonPropertyName("warehouse")] WarehousePayload? Warehouse = null,
    [property: JsonPropertyName("exchange_rate_type")] ExchangeRateTypePayload? ExchangeRateType = null);

public sealed record CreateSpinoffRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("admin")] GroupOwnerPayload Admin,
    [property: JsonPropertyName("plan")] string? Plan = "demo",
    [property: JsonPropertyName("domain")] string? Domain = null,
    [property: JsonPropertyName("branch")] BranchPayload? Branch = null,
    [property: JsonPropertyName("warehouse")] WarehousePayload? Warehouse = null,
    [property: JsonPropertyName("exchange_rate_type")] ExchangeRateTypePayload? ExchangeRateType = null);

public sealed record GroupResource(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("domain")] string? Domain,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("plan")] string? Plan,
    [property: JsonPropertyName("parent_id")] long? ParentId,
    [property: JsonPropertyName("is_group")] bool IsGroup,
    [property: JsonPropertyName("spinoffs_count")] int? SpinoffsCount,
    [property: JsonPropertyName("users_count")] int? UsersCount,
    [property: JsonPropertyName("created_at")] DateTimeOffset? CreatedAt,
    [property: JsonPropertyName("updated_at")] DateTimeOffset? UpdatedAt);

public sealed record GroupListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<GroupResource> Data,
    [property: JsonPropertyName("meta")] PaginationMeta? Meta);

public sealed record SpinoffResource(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("domain")] string? Domain,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("plan")] string? Plan,
    [property: JsonPropertyName("parent_id")] long? ParentId,
    [property: JsonPropertyName("is_group")] bool IsGroup,
    [property: JsonPropertyName("users_count")] int? UsersCount,
    [property: JsonPropertyName("created_at")] DateTimeOffset? CreatedAt,
    [property: JsonPropertyName("updated_at")] DateTimeOffset? UpdatedAt);

public sealed record SpinoffListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<SpinoffResource> Data,
    [property: JsonPropertyName("meta")] PaginationMeta? Meta);

public sealed record SingleGroupResponse(
    [property: JsonPropertyName("data")] GroupResource Data);

public sealed record SingleSpinoffResponse(
    [property: JsonPropertyName("data")] SpinoffResource Data);

public sealed record PaginationMeta(
    [property: JsonPropertyName("total")] int Total,
    [property: JsonPropertyName("per_page")] int PerPage,
    [property: JsonPropertyName("current_page")] int CurrentPage);

public sealed record PlatformAdminResource(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("email")] string Email,
    [property: JsonPropertyName("is_platform_admin")] bool IsPlatformAdmin,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("auth_tokens_count")] int AuthTokensCount,
    [property: JsonPropertyName("last_login_at")] DateTimeOffset? LastLoginAt,
    [property: JsonPropertyName("created_at")] DateTimeOffset? CreatedAt,
    [property: JsonPropertyName("updated_at")] DateTimeOffset? UpdatedAt);

public sealed record CreatePlatformAdminRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("email")] string Email,
    [property: JsonPropertyName("password")] string? Password = null);

public sealed record PlatformAdminStoreResponse(
    [property: JsonPropertyName("data")] PlatformAdminResource Data,
    [property: JsonPropertyName("initial_password")] string? InitialPassword);
