using System.Text.Json.Serialization;

namespace InventoryDesktop.Core.Security;

[JsonSerializable(typeof(PersistedSession))]
[JsonSerializable(typeof(string))]
internal partial class SessionJsonContext : JsonSerializerContext
{
}
