using System.IO;
using System.Security.Cryptography;
using System.Text;

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
}
