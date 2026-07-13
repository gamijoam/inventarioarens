using System.IO;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;

namespace InventoryDesktop.Core.Security;

public sealed class TokenVault
{
    private readonly string vaultPath;

    public TokenVault()
    {
        string folder = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
            "SistemaInventario");
        Directory.CreateDirectory(folder);
        vaultPath = Path.Combine(folder, "session.bin");
    }

    public void Save(string token)
    {
        byte[] plainBytes = Encoding.UTF8.GetBytes(token);
        byte[] protectedBytes = ProtectedData.Protect(plainBytes, null, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(vaultPath, protectedBytes);
    }

    public string? Read()
    {
        if (!File.Exists(vaultPath))
        {
            return null;
        }

        byte[] protectedBytes = File.ReadAllBytes(vaultPath);
        byte[] plainBytes = ProtectedData.Unprotect(protectedBytes, null, DataProtectionScope.CurrentUser);
        return Encoding.UTF8.GetString(plainBytes);
    }

    public void Clear()
    {
        if (File.Exists(vaultPath))
        {
            File.Delete(vaultPath);
        }
    }

    public void SaveSession(PersistedSession session)
    {
        string json = JsonSerializer.Serialize(session, SessionJsonContext.Default.PersistedSession);
        byte[] plainBytes = Encoding.UTF8.GetBytes(json);
        byte[] protectedBytes = ProtectedData.Protect(plainBytes, null, DataProtectionScope.CurrentUser);
        File.WriteAllBytes(vaultPath, protectedBytes);
    }

    public PersistedSession? ReadSession()
    {
        if (!File.Exists(vaultPath))
        {
            return null;
        }

        byte[] protectedBytes = File.ReadAllBytes(vaultPath);
        byte[] plainBytes = ProtectedData.Unprotect(protectedBytes, null, DataProtectionScope.CurrentUser);
        string json = Encoding.UTF8.GetString(plainBytes);
        if (string.IsNullOrWhiteSpace(json))
        {
            return null;
        }

        try
        {
            return JsonSerializer.Deserialize(json, SessionJsonContext.Default.PersistedSession);
        }
        catch
        {
            return null;
        }
    }

    public void SaveTokenOnly(string token) => Save(token);
}

public sealed record PersistedSession(
    string Token,
    string? TenantSlug,
    string ApiBaseUrl,
    DateTimeOffset? ExpiresAt,
    long UserId,
    string DisplayName);
