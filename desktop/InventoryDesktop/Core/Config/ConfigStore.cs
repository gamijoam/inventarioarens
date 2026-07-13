using System.IO;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace InventoryDesktop.Core.Config;

public static class ConfigStore
{
    private const string FileName = "inventorydesktop.config.json";

    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
        WriteIndented = true,
    };

    public static string ConfigPath
    {
        get
        {
            DirectoryInfo? candidate = new(AppContext.BaseDirectory);
            while (candidate is not null)
            {
                if (File.Exists(Path.Combine(candidate.FullName, "InventoryDesktop.csproj")))
                {
                    return Path.Combine(candidate.FullName, FileName);
                }
                candidate = candidate.Parent;
            }

            return Path.Combine(AppContext.BaseDirectory, FileName);
        }
    }

    public static PersistedConfig? TryRead()
    {
        try
        {
            if (!File.Exists(ConfigPath))
            {
                return null;
            }

            string json = File.ReadAllText(ConfigPath);
            return JsonSerializer.Deserialize<PersistedConfig>(json, JsonOptions);
        }
        catch
        {
            return null;
        }
    }

    public static void Save(PersistedConfig config)
    {
        string json = JsonSerializer.Serialize(config, JsonOptions);
        File.WriteAllText(ConfigPath, json);
    }

    public static bool Exists() => File.Exists(ConfigPath);
}
