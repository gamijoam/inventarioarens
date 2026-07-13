namespace InventoryDesktop.Core.Config;

public sealed record PersistedConfig(
    string ApiBaseUrl,
    bool AllowProgrammerMode)
{
    public const string DefaultApiBaseUrl = "http://127.0.0.1:8000/api/";

    public static PersistedConfig Default() =>
        new(DefaultApiBaseUrl, AllowProgrammerMode: false);

    public static PersistedConfig FromApiBaseUrl(string apiBaseUrl) =>
        new(apiBaseUrl, AllowProgrammerMode: false);
}
