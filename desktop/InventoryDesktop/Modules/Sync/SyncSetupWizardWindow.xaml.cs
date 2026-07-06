using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Windows;

namespace InventoryDesktop.Modules.Sync;

public partial class SyncSetupWizardWindow : Window
{
    private readonly SyncWorkerViewModel viewModel;
    private readonly HttpClient httpClient = new();

    public SyncSetupWizardWindow(SyncWorkerViewModel viewModel)
    {
        this.viewModel = viewModel;
        InitializeComponent();

        CloudUrlBox.Text = string.IsNullOrWhiteSpace(viewModel.CloudUrl) ? "http://217.216.80.158:8000/api" : viewModel.CloudUrl;
        EmailBox.Text = "";
        NodeCodeBox.Text = viewModel.NodeCode;
        NodeNameBox.Text = viewModel.NodeName;
        IntervalBox.Text = string.IsNullOrWhiteSpace(viewModel.Interval) ? "30" : viewModel.Interval;
    }

    private async void SearchCompanies_Click(object sender, RoutedEventArgs e)
    {
        await RunUiActionAsync(async () =>
        {
            string apiUrl = NormalizeApiUrl(CloudUrlBox.Text);
            string email = EmailBox.Text.Trim();
            if (string.IsNullOrWhiteSpace(email))
            {
                SetMessage("Escribe el correo del gerente para buscar sus empresas.", isError: true);
                return;
            }

            using HttpResponseMessage response = await httpClient.PostAsJsonAsync(
                $"{apiUrl}/auth/tenants",
                new { email });

            string json = await response.Content.ReadAsStringAsync();
            if (!response.IsSuccessStatusCode)
            {
                SetMessage(ExtractApiError(json, "No se pudieron buscar las empresas."), isError: true);
                return;
            }

            TenantLookupResponse? tenants = JsonSerializer.Deserialize<TenantLookupResponse>(json, JsonOptions());
            TenantBox.ItemsSource = tenants?.Data ?? [];
            TenantBox.SelectedIndex = TenantBox.Items.Count > 0 ? 0 : -1;

            SetMessage(TenantBox.Items.Count == 0
                ? "Ese correo no tiene empresas activas asociadas en la nube."
                : $"Se encontraron {TenantBox.Items.Count} empresa(s). Selecciona una y genera el token.",
                TenantBox.Items.Count == 0);
        });
    }

    private async void GenerateAndSave_Click(object sender, RoutedEventArgs e)
    {
        await RunUiActionAsync(async () =>
        {
            if (TenantBox.SelectedItem is not TenantOption selectedTenant)
            {
                SetMessage("Primero busca y selecciona una empresa.", isError: true);
                return;
            }

            string apiUrl = NormalizeApiUrl(CloudUrlBox.Text);
            string email = EmailBox.Text.Trim();
            string password = PasswordBox.Password;
            if (string.IsNullOrWhiteSpace(email) || string.IsNullOrWhiteSpace(password))
            {
                SetMessage("Correo y contrasena son obligatorios para autorizar la instalacion.", isError: true);
                return;
            }

            string sessionToken = await LoginAsync(apiUrl, selectedTenant.Slug, email, password);
            string syncToken = await IssueSyncTokenAsync(apiUrl, selectedTenant.Slug, sessionToken);

            int interval = int.TryParse(IntervalBox.Text, out int parsedInterval) ? parsedInterval : 30;
            viewModel.ApplyInstallerConfiguration(
                selectedTenant.Slug,
                apiUrl,
                syncToken,
                NodeCodeBox.Text.Trim(),
                NodeNameBox.Text.Trim(),
                interval);

            SetMessage("Configuracion guardada. Ya puedes sincronizar esta empresa o iniciar el worker automatico.", isError: false);
        });
    }

    private async Task<string> LoginAsync(string apiUrl, string tenantSlug, string email, string password)
    {
        using HttpRequestMessage request = new(HttpMethod.Post, $"{apiUrl}/auth/login");
        request.Headers.Add("X-Tenant", tenantSlug);
        request.Content = JsonContent.Create(new
        {
            email,
            password,
            device_name = $"Instalador {Environment.MachineName}",
        });

        using HttpResponseMessage response = await httpClient.SendAsync(request);
        string json = await response.Content.ReadAsStringAsync();
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(ExtractApiError(json, "No se pudo iniciar sesion en la nube."));
        }

        LoginResponse? login = JsonSerializer.Deserialize<LoginResponse>(json, JsonOptions());
        if (string.IsNullOrWhiteSpace(login?.Data?.Token))
        {
            throw new InvalidOperationException("La nube no devolvio token de sesion.");
        }

        return login.Data.Token;
    }

    private async Task<string> IssueSyncTokenAsync(string apiUrl, string tenantSlug, string sessionToken)
    {
        using HttpRequestMessage request = new(HttpMethod.Post, $"{apiUrl}/sync/tokens");
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", sessionToken);
        request.Headers.Add("X-Tenant", tenantSlug);
        request.Content = JsonContent.Create(new
        {
            name = $"Sync {Environment.MachineName}",
            days = 365,
        });

        using HttpResponseMessage response = await httpClient.SendAsync(request);
        string json = await response.Content.ReadAsStringAsync();
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(ExtractApiError(json, "No se pudo generar el token de sincronizacion."));
        }

        SyncTokenResponse? token = JsonSerializer.Deserialize<SyncTokenResponse>(json, JsonOptions());
        if (string.IsNullOrWhiteSpace(token?.Data?.Token))
        {
            throw new InvalidOperationException("La nube no devolvio token de sincronizacion.");
        }

        return token.Data.Token;
    }

    private async Task RunUiActionAsync(Func<Task> action)
    {
        try
        {
            SetMessage("Procesando...", isError: false);
            await action();
        }
        catch (Exception exception)
        {
            SetMessage(exception.Message, isError: true);
        }
    }

    private void SetMessage(string message, bool isError)
    {
        MessageText.Text = message;
        MessageText.Foreground = isError
            ? System.Windows.Media.Brushes.Crimson
            : System.Windows.Media.Brushes.DarkGreen;
    }

    private static string NormalizeApiUrl(string value)
    {
        string trimmed = value.Trim().TrimEnd('/');
        if (string.IsNullOrWhiteSpace(trimmed))
        {
            throw new InvalidOperationException("La URL de la nube es obligatoria.");
        }

        return trimmed.EndsWith("/api", StringComparison.OrdinalIgnoreCase) ? trimmed : $"{trimmed}/api";
    }

    private static string ExtractApiError(string json, string fallback)
    {
        try
        {
            using JsonDocument document = JsonDocument.Parse(json);
            if (document.RootElement.TryGetProperty("message", out JsonElement messageElement))
            {
                return messageElement.GetString() ?? fallback;
            }

            if (document.RootElement.TryGetProperty("errors", out JsonElement errorsElement))
            {
                foreach (JsonProperty property in errorsElement.EnumerateObject())
                {
                    if (property.Value.ValueKind == JsonValueKind.Array && property.Value.GetArrayLength() > 0)
                    {
                        return property.Value[0].GetString() ?? fallback;
                    }
                }
            }
        }
        catch
        {
            return fallback;
        }

        return fallback;
    }

    private static JsonSerializerOptions JsonOptions()
    {
        return new JsonSerializerOptions
        {
            PropertyNameCaseInsensitive = true,
        };
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}

public sealed record TenantLookupResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<TenantOption> Data);

public sealed record TenantOption(
    [property: JsonPropertyName("id")] int Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("domain")] string? Domain);

public sealed record LoginResponse(
    [property: JsonPropertyName("data")] LoginData? Data);

public sealed record LoginData(
    [property: JsonPropertyName("token")] string Token);

public sealed record SyncTokenResponse(
    [property: JsonPropertyName("data")] SyncTokenData? Data);

public sealed record SyncTokenData(
    [property: JsonPropertyName("token")] string Token,
    [property: JsonPropertyName("token_type")] string TokenType,
    [property: JsonPropertyName("expires_at")] string? ExpiresAt);
