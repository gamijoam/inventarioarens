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
    private string? bearerToken;
    private string? tenantSlug;

    public Uri BaseUri { get; private set; } = new("http://127.0.0.1:8000/api/");

    public void Configure(string apiBaseUrl, string? bearerToken = null, string? tenantSlug = null)
    {
        if (!apiBaseUrl.EndsWith("/", StringComparison.Ordinal))
        {
            apiBaseUrl += "/";
        }

        BaseUri = new Uri(apiBaseUrl, UriKind.Absolute);
        this.bearerToken = bearerToken;
        this.tenantSlug = tenantSlug;
    }

    public async Task<TResponse> PostAsync<TRequest, TResponse>(string path, TRequest payload, CancellationToken cancellationToken = default)
    {
        using HttpRequestMessage request = CreateRequest(HttpMethod.Post, path);
        request.Content = JsonContent.Create(payload, options: JsonOptions);

        using HttpResponseMessage response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadResponseAsync<TResponse>(response, cancellationToken);
    }

    public async Task<TResponse> PatchAsync<TRequest, TResponse>(string path, TRequest payload, CancellationToken cancellationToken = default)
    {
        using HttpRequestMessage request = CreateRequest(HttpMethod.Patch, path);
        request.Content = JsonContent.Create(payload, options: JsonOptions);

        using HttpResponseMessage response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadResponseAsync<TResponse>(response, cancellationToken);
    }

    public async Task<TResponse> GetAsync<TResponse>(string path, CancellationToken cancellationToken = default)
    {
        using HttpRequestMessage request = CreateRequest(HttpMethod.Get, path);

        using HttpResponseMessage response = await httpClient.SendAsync(request, cancellationToken);
        return await ReadResponseAsync<TResponse>(response, cancellationToken);
    }

    private HttpRequestMessage CreateRequest(HttpMethod method, string path)
    {
        HttpRequestMessage request = new(method, BuildUri(path));

        if (!string.IsNullOrWhiteSpace(bearerToken))
        {
            request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", bearerToken);
        }

        if (!string.IsNullOrWhiteSpace(tenantSlug))
        {
            request.Headers.Add("X-Tenant", tenantSlug);
        }

        return request;
    }

    private Uri BuildUri(string path)
    {
        return Uri.TryCreate(path, UriKind.Absolute, out Uri? absoluteUri)
            ? absoluteUri
            : new Uri(BaseUri, path);
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
        return data ?? throw new ApiException("El servidor respondió sin datos válidos.", response.StatusCode, content);
    }

    private static string? TryReadApiMessage(string content)
    {
        try
        {
            using JsonDocument document = JsonDocument.Parse(content);
            if (document.RootElement.TryGetProperty("errors", out JsonElement errors)
                && errors.ValueKind == JsonValueKind.Object)
            {
                List<string> validationMessages = [];
                foreach (JsonProperty field in errors.EnumerateObject())
                {
                    if (field.Value.ValueKind != JsonValueKind.Array)
                    {
                        continue;
                    }

                    foreach (JsonElement item in field.Value.EnumerateArray())
                    {
                        string? error = item.GetString();
                        if (!string.IsNullOrWhiteSpace(error))
                        {
                            validationMessages.Add(error);
                        }
                    }
                }

                if (validationMessages.Count > 0)
                {
                    return string.Join(Environment.NewLine, validationMessages);
                }
            }

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
