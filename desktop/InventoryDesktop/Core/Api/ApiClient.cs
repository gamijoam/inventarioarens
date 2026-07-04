using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;

namespace InventoryDesktop.Core.Api;

public sealed class ApiClient
{
    private static readonly JsonSerializerOptions JsonOptions = new()
    {
        PropertyNameCaseInsensitive = true,
    };

    private readonly HttpClient httpClient = new();

    public Uri BaseUri { get; private set; } = new("http://127.0.0.1:8000/api/");

    public void Configure(string apiBaseUrl, string? bearerToken = null, string? tenantSlug = null)
    {
        if (!apiBaseUrl.EndsWith("/", StringComparison.Ordinal))
        {
            apiBaseUrl += "/";
        }

        BaseUri = new Uri(apiBaseUrl, UriKind.Absolute);
        httpClient.BaseAddress = BaseUri;
        httpClient.DefaultRequestHeaders.Authorization = string.IsNullOrWhiteSpace(bearerToken)
            ? null
            : new AuthenticationHeaderValue("Bearer", bearerToken);

        httpClient.DefaultRequestHeaders.Remove("X-Tenant");
        if (!string.IsNullOrWhiteSpace(tenantSlug))
        {
            httpClient.DefaultRequestHeaders.Add("X-Tenant", tenantSlug);
        }
    }

    public async Task<TResponse> PostAsync<TRequest, TResponse>(string path, TRequest payload, CancellationToken cancellationToken = default)
    {
        using HttpResponseMessage response = await httpClient.PostAsJsonAsync(path, payload, JsonOptions, cancellationToken);
        return await ReadResponseAsync<TResponse>(response, cancellationToken);
    }

    public async Task<TResponse> GetAsync<TResponse>(string path, CancellationToken cancellationToken = default)
    {
        using HttpResponseMessage response = await httpClient.GetAsync(path, cancellationToken);
        return await ReadResponseAsync<TResponse>(response, cancellationToken);
    }

    private static async Task<TResponse> ReadResponseAsync<TResponse>(HttpResponseMessage response, CancellationToken cancellationToken)
    {
        string content = await response.Content.ReadAsStringAsync(cancellationToken);
        if (!response.IsSuccessStatusCode)
        {
            string message = TryReadApiMessage(content) ?? $"Solicitud rechazada por el servidor: {(int)response.StatusCode}.";
            throw new ApiException(message, response.StatusCode, content);
        }

        TResponse? data = JsonSerializer.Deserialize<TResponse>(content, JsonOptions);
        return data ?? throw new ApiException("El servidor respondio sin datos validos.", response.StatusCode, content);
    }

    private static string? TryReadApiMessage(string content)
    {
        try
        {
            using JsonDocument document = JsonDocument.Parse(content);
            if (document.RootElement.TryGetProperty("message", out JsonElement message))
            {
                return message.GetString();
            }
        }
        catch (JsonException)
        {
            return null;
        }

        return null;
    }
}
