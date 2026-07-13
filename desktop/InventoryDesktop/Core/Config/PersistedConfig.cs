namespace InventoryDesktop.Core.Config;

public sealed record PersistedConfig(
    string ApiBaseUrl,
    bool AllowProgrammerMode,
    string? PlatformApiBaseUrl = null)
{
    public const string DefaultApiBaseUrl = "http://127.0.0.1:8000/api/";

    public static PersistedConfig Default() =>
        new(DefaultApiBaseUrl, AllowProgrammerMode: false, PlatformApiBaseUrl: null);
}
