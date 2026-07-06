using System.Diagnostics;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Net.Http.Json;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Windows;
using System.Windows.Controls;

namespace InventorySyncInstaller;

public partial class MainWindow : Window
{
    private readonly HttpClient http = new();
    private readonly JsonSerializerOptions jsonOptions = new(JsonSerializerDefaults.Web);
    private readonly string repoRoot;
    private readonly string phpPath;
    private readonly string dotnetPath;

    public MainWindow()
    {
        InitializeComponent();
        repoRoot = FindRepoRoot() ?? Environment.CurrentDirectory;
        phpPath = FindPhpPath();
        dotnetPath = FindDotnetPath();
        NodeNameBox.Text = $"Local {Environment.MachineName}";
    }

    private async void SearchCompanies_Click(object sender, RoutedEventArgs e)
    {
        await RunUiAsync(async () =>
        {
            OpenMainButton.Visibility = Visibility.Collapsed;
            SetState("Buscando", "Consultando empresas", "Estamos buscando las empresas asociadas a este correo en la nube.", 1);
            TenantBox.ItemsSource = null;

            List<TenantOption> tenants = await SearchTenantsAsync();
            TenantBox.ItemsSource = tenants;

            if (tenants.Count == 0)
            {
                SetState("Sin empresas", "No hay empresas asociadas", "Verifica el correo o que el usuario exista en la nube.");
                AppendLog("La nube no devolvio empresas para este correo.");
                return;
            }

            TenantBox.SelectedIndex = 0;
            SetState("Empresas listas", "Selecciona la empresa", $"Se encontraron {tenants.Count} empresa(s). Puedes continuar con la configuracion.", 1);
            AppendLog($"Empresas encontradas: {tenants.Count}");
        });
    }

    private async void Prepare_Click(object sender, RoutedEventArgs e)
    {
        await RunUiAsync(async () =>
        {
            if (TenantBox.SelectedItem is not TenantOption selectedTenant)
            {
                List<TenantOption> tenants = await SearchTenantsAsync();
                TenantBox.ItemsSource = tenants;
                selectedTenant = tenants.FirstOrDefault() ?? throw new InvalidOperationException("No hay empresas para preparar.");
                TenantBox.SelectedItem = selectedTenant;
            }

            string cloudUrl = NormalizeCloudUrl(CloudUrlBox.Text);
            string email = EmailBox.Text.Trim();
            string password = PasswordBox.Password;
            string nodeName = string.IsNullOrWhiteSpace(NodeNameBox.Text) ? $"Local {Environment.MachineName}" : NodeNameBox.Text.Trim();
            int interval = SelectedInterval();
            string installationCode = BuildInstallationCode(selectedTenant.Slug);
            string nodeCode = installationCode;

            OpenMainButton.Visibility = Visibility.Collapsed;
            SetState("Validando", "Validando acceso", "Confirmando el usuario administrador en la nube.", 0);
            CloudLogin login = await LoginAsync(cloudUrl, selectedTenant.Slug, email, password);

            SetState("Autorizando", "Preparando token seguro", "Registrando esta computadora para sincronizar con la empresa seleccionada.", 1);
            string syncToken = await IssueSyncTokenAsync(cloudUrl, selectedTenant.Slug, login.Token, nodeName);

            SetState("Preparando", "Preparando base local", "Creando las tablas necesarias y registrando la empresa en esta computadora.", 2);
            await RunArtisanAsync(["migrate", "--force"]);
            await PrepareLocalTenantAsync(selectedTenant, email, password, nodeName);

            SetState("Guardando", "Guardando configuracion", "Registrando servidor, empresa y frecuencia de sincronizacion.", 3);
            SaveSyncConfiguration(selectedTenant.Slug, cloudUrl, syncToken, nodeCode, nodeName, installationCode, interval);

            SetState("Preparando", "Deteniendo sincronizacion anterior", "Si esta empresa tenia un worker abierto, se detendra antes de continuar.", 4);
            await RunWorkerAsync("stop", selectedTenant.Slug, nodeCode, nodeName, installationCode, cloudUrl, syncToken, interval);

            SetState("Sincronizando", "Sincronizando datos iniciales", "Descargando productos, precios, cajas y permisos disponibles para esta empresa.", 4);
            await RunWorkerAsync("run", selectedTenant.Slug, nodeCode, nodeName, installationCode, cloudUrl, syncToken, interval);

            SetState("Activando", "Activando sincronizacion automatica", $"Windows mantendra activo el worker de esta empresa.", 5);
            await RunWorkerTaskAsync("install", selectedTenant.Slug);

            SetState("Completado", "Configuracion completada", "Esta computadora ya esta lista para abrir el Sistema de Inventario.", 6);
            TechnicalSummaryText.Text = $"Empresa: {selectedTenant.Name} | Equipo: {nodeName} | Sincronizacion cada {interval} segundos.";
            OpenMainButton.Visibility = Visibility.Visible;
            AppendLog("Configuracion finalizada correctamente.");
        });
    }

    private async void InstallTask_Click(object sender, RoutedEventArgs e)
    {
        await RunTaskButtonAsync("install", "Instalando", "Instalando sincronizacion automatica", "Windows revisara cada pocos minutos que el worker este activo.");
    }

    private async void StartTask_Click(object sender, RoutedEventArgs e)
    {
        await RunTaskButtonAsync("start", "Iniciando", "Iniciando sincronizacion", "Se levantara el worker de la empresa seleccionada.");
    }

    private async void StopTask_Click(object sender, RoutedEventArgs e)
    {
        await RunTaskButtonAsync("stop", "Deteniendo", "Deteniendo sincronizacion", "El worker se detendra para esta empresa.");
    }

    private async void TaskStatus_Click(object sender, RoutedEventArgs e)
    {
        await RunTaskButtonAsync("status", "Consultando", "Consultando estado", "Revisando la tarea de Windows y el worker activo.");
    }

    private async Task RunTaskButtonAsync(string action, string badge, string title, string detail)
    {
        await RunUiAsync(async () =>
        {
            string tenantSlug = SelectedTenantSlug();
            SetState(badge, title, detail);
            await RunWorkerTaskAsync(action, tenantSlug);
            SetState("Listo", "Operacion completada", "La sincronizacion automatica fue actualizada correctamente.");
        });
    }

    private async Task<List<TenantOption>> SearchTenantsAsync()
    {
        string cloudUrl = NormalizeCloudUrl(CloudUrlBox.Text);
        string email = EmailBox.Text.Trim();
        string password = PasswordBox.Password;

        if (string.IsNullOrWhiteSpace(email))
        {
            throw new InvalidOperationException("Escribe el correo del usuario.");
        }

        if (string.IsNullOrWhiteSpace(password))
        {
            throw new InvalidOperationException("Escribe la contrasena antes de buscar empresas.");
        }

        using HttpResponseMessage response = await http.PostAsJsonAsync($"{cloudUrl}/auth/tenants", new
        {
            email,
        }, jsonOptions);

        string body = await response.Content.ReadAsStringAsync();
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(FriendlyApiError(body, "No se pudieron buscar las empresas."));
        }

        TenantLookupResponse? payload = JsonSerializer.Deserialize<TenantLookupResponse>(body, jsonOptions);
        List<TenantOption> candidates = payload?.Data?.Where(item => !string.IsNullOrWhiteSpace(item.Slug)).ToList() ?? [];

        if (candidates.Count == 0)
        {
            return [];
        }

        List<TenantOption> validated = [];
        foreach (TenantOption candidate in candidates)
        {
            if (await ValidateTenantAccessAsync(cloudUrl, candidate.Slug, email, password))
            {
                validated.Add(candidate);
            }
        }

        if (validated.Count == 0)
        {
            throw new InvalidOperationException("Correo o contrasena invalidos para las empresas encontradas.");
        }

        return validated;
    }

    private async Task<bool> ValidateTenantAccessAsync(string cloudUrl, string tenantSlug, string email, string password)
    {
        using HttpRequestMessage request = new(HttpMethod.Post, $"{cloudUrl}/auth/login");
        request.Headers.Add("X-Tenant", tenantSlug);
        request.Content = JsonContent.Create(new
        {
            email,
            password,
            device_name = $"instalador-validacion-{Environment.MachineName}",
        }, options: jsonOptions);

        using HttpResponseMessage response = await http.SendAsync(request);
        return response.IsSuccessStatusCode;
    }

    private async Task<CloudLogin> LoginAsync(string cloudUrl, string tenantSlug, string email, string password)
    {
        if (string.IsNullOrWhiteSpace(password))
        {
            throw new InvalidOperationException("Escribe la contrasena.");
        }

        using HttpRequestMessage request = new(HttpMethod.Post, $"{cloudUrl}/auth/login");
        request.Headers.Add("X-Tenant", tenantSlug);
        request.Content = JsonContent.Create(new
        {
            email,
            password,
            device_name = $"instalador-{Environment.MachineName}",
        }, options: jsonOptions);

        using HttpResponseMessage response = await http.SendAsync(request);
        string body = await response.Content.ReadAsStringAsync();
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(FriendlyApiError(body, "No se pudo iniciar sesion en la nube."));
        }

        CloudLoginResponse payload = JsonSerializer.Deserialize<CloudLoginResponse>(body, jsonOptions)
            ?? throw new InvalidOperationException("La nube respondio sin datos de sesion.");

        if (string.IsNullOrWhiteSpace(payload.Data?.Token))
        {
            throw new InvalidOperationException("La nube no devolvio token de sesion.");
        }

        AppendLog("Login de nube correcto.");
        return payload.Data;
    }

    private async Task<string> IssueSyncTokenAsync(string cloudUrl, string tenantSlug, string authToken, string nodeName)
    {
        using HttpRequestMessage request = new(HttpMethod.Post, $"{cloudUrl}/sync/tokens");
        request.Headers.Add("X-Tenant", tenantSlug);
        request.Headers.Authorization = new AuthenticationHeaderValue("Bearer", authToken);
        request.Content = JsonContent.Create(new
        {
            name = $"Instalacion {nodeName}",
            days = 365,
        }, options: jsonOptions);

        using HttpResponseMessage response = await http.SendAsync(request);
        string body = await response.Content.ReadAsStringAsync();
        if (!response.IsSuccessStatusCode)
        {
            throw new InvalidOperationException(FriendlyApiError(body, "No se pudo generar el token de sincronizacion."));
        }

        SyncTokenResponse payload = JsonSerializer.Deserialize<SyncTokenResponse>(body, jsonOptions)
            ?? throw new InvalidOperationException("La nube respondio sin token de sincronizacion.");

        if (string.IsNullOrWhiteSpace(payload.Data?.Token))
        {
            throw new InvalidOperationException("La nube no devolvio token de sincronizacion.");
        }

        AppendLog("Token de sincronizacion generado.");
        return payload.Data.Token;
    }

    private async Task PrepareLocalTenantAsync(TenantOption tenant, string email, string password, string nodeName)
    {
        Dictionary<string, string> environment = new()
        {
            ["SYNC_BOOTSTRAP_PASSWORD"] = password,
        };

        await RunArtisanAsync([
            "sync:prepare-local",
            tenant.Slug,
            tenant.Name,
            email,
            $"--user-name={nodeName}",
            $"--domain={tenant.Domain ?? ""}",
        ], environment);
    }

    private async Task RunArtisanAsync(IReadOnlyList<string> arguments, Dictionary<string, string>? environment = null)
    {
        if (!File.Exists(Path.Combine(repoRoot, "artisan")))
        {
            throw new InvalidOperationException("No se encontro el proyecto Laravel. Ejecuta este instalador desde la carpeta del sistema.");
        }

        ProcessStartInfo info = new()
        {
            FileName = phpPath,
            WorkingDirectory = repoRoot,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };

        info.ArgumentList.Add("artisan");
        foreach (string argument in arguments)
        {
            info.ArgumentList.Add(argument);
        }

        if (environment is not null)
        {
            foreach ((string key, string value) in environment)
            {
                info.Environment[key] = value;
            }
        }

        await RunProcessAsync(info);
    }

    private async Task RunWorkerAsync(string action, string tenantSlug, string nodeCode, string nodeName, string installationCode, string cloudUrl, string token, int interval)
    {
        string worker = Path.Combine(repoRoot, "scripts", "sync-worker.cmd");
        if (!File.Exists(worker))
        {
            throw new InvalidOperationException("No se encontro scripts\\sync-worker.cmd.");
        }

        string command =
            $"{Quote(worker)} {action} -PhpPath {Quote(phpPath)} -TenantSlug {Quote(tenantSlug)} -NodeCode {Quote(nodeCode)} " +
            $"-NodeName {Quote(nodeName)} -InstallationCode {Quote(installationCode)} -CloudUrl {Quote(cloudUrl)} -Token {Quote(token)} -Interval {interval}";

        ProcessStartInfo info = new()
        {
            FileName = "cmd.exe",
            Arguments = $"/d /c \"{command}\"",
            WorkingDirectory = repoRoot,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };

        await RunProcessAsync(info);
    }

    private async Task RunWorkerTaskAsync(string action, string tenantSlug)
    {
        string taskScript = Path.Combine(repoRoot, "scripts", "sync-worker-task.cmd");
        if (!File.Exists(taskScript))
        {
            throw new InvalidOperationException("No se encontro scripts\\sync-worker-task.cmd.");
        }

        string command = $"{Quote(taskScript)} {action} -TenantSlug {Quote(tenantSlug)}";

        ProcessStartInfo info = new()
        {
            FileName = "cmd.exe",
            Arguments = $"/d /c \"{command}\"",
            WorkingDirectory = repoRoot,
            UseShellExecute = false,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
            CreateNoWindow = true,
        };

        await RunProcessAsync(info);
    }

    private async Task RunProcessAsync(ProcessStartInfo info)
    {
        using Process process = Process.Start(info) ?? throw new InvalidOperationException("No se pudo iniciar el proceso.");
        string output = await process.StandardOutput.ReadToEndAsync();
        string error = await process.StandardError.ReadToEndAsync();
        await process.WaitForExitAsync();

        string combined = string.Join(Environment.NewLine, new[] { output.Trim(), error.Trim() }.Where(value => !string.IsNullOrWhiteSpace(value)));
        if (!string.IsNullOrWhiteSpace(combined))
        {
            AppendLog(combined);
        }

        if (process.ExitCode != 0)
        {
            throw new InvalidOperationException(FriendlyProcessError(combined));
        }
    }

    private void SaveSyncConfiguration(string tenantSlug, string cloudUrl, string token, string nodeCode, string nodeName, string installationCode, int interval)
    {
        string path = Path.Combine(repoRoot, "storage", "app", "sync-worker", "sync-config.json");
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);

        SyncWorkerConfigurationFile file = File.Exists(path)
            ? JsonSerializer.Deserialize<SyncWorkerConfigurationFile>(File.ReadAllText(path), jsonOptions) ?? new SyncWorkerConfigurationFile()
            : new SyncWorkerConfigurationFile();

        file.Tenants[tenantSlug] = new SyncTenantConfiguration
        {
            TenantSlug = tenantSlug,
            InstallationCode = installationCode,
            NodeCode = nodeCode,
            NodeName = nodeName,
            CloudUrl = cloudUrl,
            Token = token,
            Interval = interval,
            UpdatedAt = DateTimeOffset.Now,
        };

        File.WriteAllText(path, JsonSerializer.Serialize(file, new JsonSerializerOptions(JsonSerializerDefaults.Web)
        {
            WriteIndented = true,
        }));

        AppendLog($"Configuracion guardada para {tenantSlug}.");
    }

    private async Task RunUiAsync(Func<Task> action)
    {
        ToggleBusy(true);
        try
        {
            await action();
        }
        catch (Exception exception)
        {
            string friendlyMessage = FriendlyException(exception);
            SetState("Error", "No se pudo completar", friendlyMessage);
            TechnicalSummaryText.Text = "Abre los detalles tecnicos solo si necesitas soporte.";
            AppendLog(exception.Message);
        }
        finally
        {
            ToggleBusy(false);
        }
    }

    private void ToggleBusy(bool busy)
    {
        BusyBar.Visibility = busy ? Visibility.Visible : Visibility.Collapsed;
        SearchCompaniesButton.IsEnabled = !busy;
        PrepareButton.IsEnabled = !busy;
        OpenMainButton.IsEnabled = !busy;
        InstallTaskButton.IsEnabled = !busy;
        StartTaskButton.IsEnabled = !busy;
        StopTaskButton.IsEnabled = !busy;
        TaskStatusButton.IsEnabled = !busy;
    }

    private void SetState(string badge, string title, string detail, int? stepIndex = null)
    {
        StateBadge.Text = badge;
        StateTitle.Text = title;
        StateDetail.Text = detail;
        if (stepIndex is not null)
        {
            StepsList.SelectedIndex = stepIndex.Value;
            StepsList.ScrollIntoView(StepsList.SelectedItem);
        }

        AppendLog($"{title}: {detail}");
    }

    private void AppendLog(string text)
    {
        if (string.IsNullOrWhiteSpace(text))
        {
            return;
        }

        LogBox.AppendText($"[{DateTime.Now:HH:mm:ss}] {text}{Environment.NewLine}");
        LogBox.ScrollToEnd();
    }

    private int SelectedInterval()
    {
        if (IntervalBox.SelectedItem is ComboBoxItem item &&
            int.TryParse(item.Tag?.ToString(), out int value))
        {
            return value;
        }

        return 30;
    }

    private string SelectedTenantSlug()
    {
        if (TenantBox.SelectedItem is TenantOption selectedTenant)
        {
            return selectedTenant.Slug;
        }

        string? selectedValue = TenantBox.SelectedValue?.ToString();
        if (!string.IsNullOrWhiteSpace(selectedValue))
        {
            return selectedValue;
        }

        throw new InvalidOperationException("Selecciona una empresa antes de usar la sincronizacion automatica.");
    }

    private static string NormalizeCloudUrl(string value)
    {
        string url = value.Trim().TrimEnd('/');
        if (string.IsNullOrWhiteSpace(url))
        {
            throw new InvalidOperationException("Escribe la URL de la nube.");
        }

        if (!Uri.TryCreate(url, UriKind.Absolute, out _))
        {
            throw new InvalidOperationException("La URL de la nube no es valida.");
        }

        return url;
    }

    private static string BuildInstallationCode(string tenantSlug)
    {
        string machine = CleanCode(Environment.MachineName);
        string tenant = CleanCode(tenantSlug);

        return $"LOCAL-{tenant}-{machine}".ToUpperInvariant();
    }

    private static string CleanCode(string value)
    {
        string clean = new(value.Select(character =>
            char.IsLetterOrDigit(character) ? char.ToUpperInvariant(character) : '-').ToArray());

        return string.Join("-", clean.Split('-', StringSplitOptions.RemoveEmptyEntries));
    }

    private static string Quote(string value)
    {
        return "\"" + value.Replace("\"", "\\\"") + "\"";
    }

    private static string FriendlyApiError(string body, string fallback)
    {
        try
        {
            using JsonDocument document = JsonDocument.Parse(body);
            if (document.RootElement.TryGetProperty("message", out JsonElement message))
            {
                return message.GetString() ?? fallback;
            }

            if (document.RootElement.TryGetProperty("errors", out JsonElement errors))
            {
                return errors.ToString();
            }
        }
        catch
        {
            // Si la nube responde HTML o texto plano, se muestra el mensaje base.
        }

        return fallback;
    }

    private static string FriendlyException(Exception exception)
    {
        if (exception is HttpRequestException)
        {
            return "No se pudo conectar con el servidor de la nube. Verifica que la URL este bien escrita, que Laravel este encendido en el VPS y que el puerto este abierto.";
        }

        if (exception is TaskCanceledException)
        {
            return "El servidor de la nube tardo demasiado en responder. Revisa la conexion a internet o intenta nuevamente.";
        }

        if (exception is JsonException)
        {
            return "La nube respondio, pero la respuesta no tiene el formato esperado. Revisa que la URL apunte a la API correcta.";
        }

        return exception.Message;
    }

    private static string FriendlyProcessError(string output)
    {
        if (output.Contains("No se encontro la empresa indicada", StringComparison.OrdinalIgnoreCase))
        {
            return "La empresa no quedo preparada localmente. Repite el proceso o revisa el log.";
        }

        if (output.Contains("Falta Token", StringComparison.OrdinalIgnoreCase))
        {
            return "El token no quedo configurado. Vuelve a preparar esta computadora.";
        }

        if (output.Contains("could not find driver", StringComparison.OrdinalIgnoreCase))
        {
            return "PHP no tiene el driver de PostgreSQL activo. Revisa la instalacion de Laragon.";
        }

        return string.IsNullOrWhiteSpace(output) ? "El proceso termino con error." : output;
    }

    private static string? FindRepoRoot()
    {
        DirectoryInfo? current = new(Environment.CurrentDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "artisan")))
            {
                return current.FullName;
            }

            current = current.Parent;
        }

        current = new(AppContext.BaseDirectory);
        while (current is not null)
        {
            if (File.Exists(Path.Combine(current.FullName, "artisan")))
            {
                return current.FullName;
            }

            current = current.Parent;
        }

        return null;
    }

    private static string FindPhpPath()
    {
        string? fromEnvironment = Environment.GetEnvironmentVariable("PHP_EXE");
        if (!string.IsNullOrWhiteSpace(fromEnvironment) && File.Exists(fromEnvironment))
        {
            return fromEnvironment;
        }

        string laragon = @"C:\laragon\bin\php\php-8.4.23-Win32-vs17-x64\php.exe";
        if (File.Exists(laragon))
        {
            return laragon;
        }

        return "php";
    }

    private static string FindDotnetPath()
    {
        string programFiles = Environment.GetFolderPath(Environment.SpecialFolder.ProgramFiles);
        string installed = Path.Combine(programFiles, "dotnet", "dotnet.exe");
        if (File.Exists(installed))
        {
            return installed;
        }

        return "dotnet";
    }

    private void TechnicalDetails_Click(object sender, RoutedEventArgs e)
    {
        bool showDetails = TechnicalDetailsPanel.Visibility != Visibility.Visible;
        TechnicalDetailsPanel.Visibility = showDetails ? Visibility.Visible : Visibility.Collapsed;
        TechnicalDetailsButton.Content = showDetails ? "Ocultar detalles tecnicos" : "Ver detalles tecnicos";
    }

    private void OpenMain_Click(object sender, RoutedEventArgs e)
    {
        string projectPath = Path.Combine(repoRoot, "desktop", "InventoryDesktop", "InventoryDesktop.csproj");
        if (!File.Exists(projectPath))
        {
            MessageBox.Show("No se encontro la aplicacion principal.", "Sistema de Inventario", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        ProcessStartInfo info = new()
        {
            FileName = dotnetPath,
            WorkingDirectory = repoRoot,
            UseShellExecute = false,
            CreateNoWindow = true,
        };
        info.ArgumentList.Add("run");
        info.ArgumentList.Add("--project");
        info.ArgumentList.Add(projectPath);

        Process.Start(info);
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}

public sealed record TenantLookupResponse(
    [property: JsonPropertyName("data")] List<TenantOption>? Data);

public sealed record TenantOption(
    [property: JsonPropertyName("id")] int Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("slug")] string Slug,
    [property: JsonPropertyName("domain")] string? Domain);

public sealed record CloudLoginResponse(
    [property: JsonPropertyName("data")] CloudLogin? Data);

public sealed record CloudLogin(
    [property: JsonPropertyName("token")] string Token,
    [property: JsonPropertyName("tenant")] TenantOption Tenant);

public sealed record SyncTokenResponse(
    [property: JsonPropertyName("data")] SyncTokenData? Data);

public sealed record SyncTokenData(
    [property: JsonPropertyName("token")] string Token);

public sealed class SyncWorkerConfigurationFile
{
    [JsonPropertyName("tenants")]
    public Dictionary<string, SyncTenantConfiguration> Tenants { get; set; } = new(StringComparer.OrdinalIgnoreCase);
}

public sealed class SyncTenantConfiguration
{
    [JsonPropertyName("tenant_slug")]
    public string TenantSlug { get; set; } = "";

    [JsonPropertyName("installation_code")]
    public string InstallationCode { get; set; } = "";

    [JsonPropertyName("node_code")]
    public string NodeCode { get; set; } = "";

    [JsonPropertyName("node_name")]
    public string NodeName { get; set; } = "";

    [JsonPropertyName("cloud_url")]
    public string CloudUrl { get; set; } = "";

    [JsonPropertyName("token")]
    public string Token { get; set; } = "";

    [JsonPropertyName("interval")]
    public int Interval { get; set; } = 30;

    [JsonPropertyName("updated_at")]
    public DateTimeOffset UpdatedAt { get; set; } = DateTimeOffset.Now;
}
