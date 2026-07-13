namespace InventoryDesktop.Core.Config;

public sealed record PersistedConfig(
    string ApiBaseUrl,
    bool AllowProgrammerMode,
    string? PlatformApiBaseUrl = null,
    string? RepoRoot = null,
    string? PhpPath = null)
{
    public const string DefaultApiBaseUrl = "http://127.0.0.1:8000/api/";
    public const string DefaultPhpPath = @"C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe";

    public static PersistedConfig Default() =>
        new(DefaultApiBaseUrl, AllowProgrammerMode: false, PlatformApiBaseUrl: null, RepoRoot: null, PhpPath: null);

    public string ResolvePhpPath() =>
        !string.IsNullOrWhiteSpace(PhpPath) ? PhpPath : DefaultPhpPath;
}
