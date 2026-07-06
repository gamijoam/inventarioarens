using System.Diagnostics;
using System.IO;
using System.Text.Json;
using System.Text.Json.Serialization;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.Sync;

public sealed class SyncWorkerViewModel : ViewModelBase
{
    private DesktopSession? session;
    private ApiClient? apiClient;
    private string tenantSlug = "";
    private string installationCode = "LOCAL-PC";
    private string nodeCode = "LOCAL-01";
    private string nodeName = "Local principal";
    private string cloudUrl = "";
    private string token = "";
    private string interval = "30";
    private string status = "Sin consultar";
    private string statusDetail = "Presiona Actualizar estado para revisar el worker.";
    private string backendStatus = "Sin consultar.";
    private string lastLog = "Sin log disponible.";
    private string message = "";
    private string configurationStatus = "Configuracion no guardada para esta empresa.";
    private string effectiveSchedule = "Automatico: detenido. Al iniciar, sincroniza cada 30 segundos.";
    private bool isBusy;

    public string TenantSlug
    {
        get => tenantSlug;
        set => SetProperty(ref tenantSlug, value);
    }

    public string InstallationCode
    {
        get => installationCode;
        set => SetProperty(ref installationCode, value);
    }

    public string NodeCode
    {
        get => nodeCode;
        set => SetProperty(ref nodeCode, value);
    }

    public string NodeName
    {
        get => nodeName;
        set => SetProperty(ref nodeName, value);
    }

    public string CloudUrl
    {
        get => cloudUrl;
        set => SetProperty(ref cloudUrl, value);
    }

    public string Token
    {
        get => token;
        set => SetProperty(ref token, value);
    }

    public string Interval
    {
        get => interval;
        set => SetProperty(ref interval, value);
    }

    public string Status
    {
        get => status;
        set => SetProperty(ref status, value);
    }

    public string StatusDetail
    {
        get => statusDetail;
        set => SetProperty(ref statusDetail, value);
    }

    public string BackendStatus
    {
        get => backendStatus;
        set => SetProperty(ref backendStatus, value);
    }

    public string LastLog
    {
        get => lastLog;
        set => SetProperty(ref lastLog, value);
    }

    public string Message
    {
        get => message;
        set => SetProperty(ref message, value);
    }

    public string ConfigurationStatus
    {
        get => configurationStatus;
        set => SetProperty(ref configurationStatus, value);
    }

    public string EffectiveSchedule
    {
        get => effectiveSchedule;
        set => SetProperty(ref effectiveSchedule, value);
    }

    public bool IsBusy
    {
        get => isBusy;
        set => SetProperty(ref isBusy, value);
    }

    public void Configure(DesktopSession activeSession)
    {
        session = activeSession;
        apiClient = activeSession.ApiClient;
        TenantSlug = activeSession.TenantSlug;
        InstallationCode = BuildInstallationCode();
        NodeCode = InstallationCode;
        NodeName = $"{Environment.MachineName} - {activeSession.TenantName}";
        LoadLocalConfiguration();
        UpdateEffectiveSchedule();
    }

    public async Task RefreshAsync()
    {
        await ExecuteWorkerAsync("status");
        await LoadBackendStatusAsync();
        LoadLogTail();
    }

    public async Task StartAsync()
    {
        SaveConfiguration(showMessage: false);
        await ExecuteWorkerAsync("start");
        await LoadBackendStatusAsync();
        LoadLogTail();
    }

    public async Task StopAsync()
    {
        await ExecuteWorkerAsync("stop");
        await LoadBackendStatusAsync();
        LoadLogTail();
    }

    public async Task RunOnceAsync()
    {
        SaveConfiguration(showMessage: false);
        await ExecuteWorkerAsync("run");
        await LoadBackendStatusAsync();
        LoadLogTail();
    }

    public void SaveConfiguration(bool showMessage = true)
    {
        try
        {
            if (string.IsNullOrWhiteSpace(TenantSlug))
            {
                Message = "Selecciona una empresa antes de guardar la sincronizacion.";
                return;
            }

            if (!string.IsNullOrWhiteSpace(CloudUrl) &&
                !Uri.TryCreate(CloudUrl, UriKind.Absolute, out _))
            {
                Message = "La URL de la nube no parece valida. Debe verse como http://servidor:puerto/api.";
                return;
            }

            if (!int.TryParse(Interval, out int parsedInterval) || parsedInterval < 5)
            {
                Interval = "30";
                parsedInterval = 30;
            }

            SyncWorkerConfigurationFile file = LoadConfigurationFile();
            file.Tenants[TenantSlug] = new SyncTenantConfiguration
            {
                TenantSlug = TenantSlug,
                InstallationCode = InstallationCode,
                NodeCode = NodeCode,
                NodeName = NodeName,
                CloudUrl = CloudUrl,
                Token = Token,
                Interval = parsedInterval,
                UpdatedAt = DateTimeOffset.Now,
            };

            SaveConfigurationFile(file);
            ConfigurationStatus = string.IsNullOrWhiteSpace(Token)
                ? "Configuracion guardada. Esta empresa usara el token del .env si existe."
                : "Configuracion guardada con token propio para esta empresa.";
            UpdateEffectiveSchedule();

            if (showMessage)
            {
                Message = "Configuracion de sincronizacion guardada para esta empresa.";
            }
        }
        catch (Exception exception)
        {
            Message = $"No se pudo guardar la configuracion local. {exception.Message}";
        }
    }

    private async Task LoadBackendStatusAsync()
    {
        if (apiClient is null)
        {
            BackendStatus = "No hay API configurada para consultar eventos locales.";
            return;
        }

        try
        {
            SyncStatusResponse response = await apiClient.GetAsync<SyncStatusResponse>("sync/status");
            SyncReadinessResponse readiness = await apiClient.GetAsync<SyncReadinessResponse>(
                $"sync/local-readiness?installation_code={Uri.EscapeDataString(InstallationCode)}");

            ApplyReadinessStatus(readiness.Data);
            BackendStatus = FormatBackendStatus(response.Data);
        }
        catch (Exception exception)
        {
            BackendStatus = $"No se pudo consultar el estado local de sincronizacion. {exception.Message}";
        }
    }

    private void ApplyReadinessStatus(SyncReadinessData? readiness)
    {
        if (readiness is null)
        {
            Status = "Sin consultar";
            StatusDetail = "No se pudo consultar si esta empresa esta lista en esta computadora.";
            return;
        }

        Status = readiness.Status switch
        {
            "ready" => "Sincronizado",
            "syncing" => "Sincronizando",
            "warning" => "Advertencia",
            "error" => "Error",
            _ => "Pendiente",
        };

        StatusDetail = readiness.Status switch
        {
            "ready" => "Esta empresa esta lista para trabajar en esta computadora.",
            "syncing" => "Esta empresa se esta sincronizando en esta computadora.",
            "warning" => readiness.LastError ?? "La ultima sincronizacion termino con advertencias.",
            "error" => readiness.LastError ?? "La empresa no pudo sincronizarse correctamente en esta computadora.",
            _ => "Esta empresa todavia no tiene sincronizacion inicial completa en esta computadora.",
        };
    }

    private static string FormatBackendStatus(SyncStatusData? data)
    {
        if (data is null)
        {
            return "Sin datos locales.";
        }

        List<string> lines =
        [
            $"Nodos registrados: {data.Nodes}",
            $"Outbox local: {data.Outbox?.Pending ?? 0} pendientes, {data.Outbox?.Processed ?? 0} procesados, {data.Outbox?.Failed ?? 0} fallidos.",
            $"Inbox local: {data.Inbox?.Received ?? 0} recibidos, {data.Inbox?.Applied ?? 0} aplicados, {data.Inbox?.Failed ?? 0} fallidos.",
            "",
            "Ultimos eventos locales:",
        ];

        IReadOnlyList<SyncStatusEvent> outboxEvents = data.LatestEvents?.Outbox ?? [];
        IReadOnlyList<SyncStatusEvent> inboxEvents = data.LatestEvents?.Inbox ?? [];

        if (outboxEvents.Count == 0 && inboxEvents.Count == 0)
        {
            lines.Add("Sin eventos registrados.");
        }

        foreach (SyncStatusEvent item in outboxEvents.Take(5))
        {
            lines.Add($"OUT #{item.Id} {item.Status} - {item.EventType} ({item.AggregateType})");
        }

        foreach (SyncStatusEvent item in inboxEvents.Take(5))
        {
            lines.Add($"IN  #{item.Id} {item.Status} - {item.EventType} ({item.AggregateType})");
        }

        return string.Join(Environment.NewLine, lines);
    }

    private async Task ExecuteWorkerAsync(string action)
    {
        if (session is null)
        {
            Message = "No hay una sesion activa para sincronizar.";
            return;
        }

        string scriptPath = ResolveScriptPath();
        if (string.IsNullOrWhiteSpace(scriptPath))
        {
            Message = "No se encontro scripts\\sync-worker.cmd en el proyecto.";
            Status = "No configurado";
            return;
        }

        IsBusy = true;
        Message = "";

        try
        {
            string effectiveCloudUrl = string.IsNullOrWhiteSpace(CloudUrl) ? LoadConfiguredCloudUrl() : CloudUrl;
            string effectiveToken = string.IsNullOrWhiteSpace(Token) ? LoadConfiguredToken() : Token;

            List<string> commandArguments = new()
            {
                action,
                "-TenantSlug",
                TenantSlug,
                "-NodeCode",
                NodeCode,
                "-NodeName",
                NodeName,
                "-InstallationCode",
                InstallationCode,
                "-Interval",
                Interval,
            };

            if (!string.IsNullOrWhiteSpace(effectiveCloudUrl))
            {
                commandArguments.Add("-CloudUrl");
                commandArguments.Add(effectiveCloudUrl);
            }

            if (!string.IsNullOrWhiteSpace(effectiveToken))
            {
                commandArguments.Add("-Token");
                commandArguments.Add(effectiveToken);
            }

            string command = $"{QuoteForCmd(scriptPath)} {string.Join(" ", commandArguments.Select(QuoteForCmd))}";

            ProcessStartInfo info = new()
            {
                FileName = "cmd.exe",
                Arguments = $"/d /c \"{command}\"",
                WorkingDirectory = FindRepoRoot() ?? AppContext.BaseDirectory,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = true,
            };

            using Process process = Process.Start(info) ?? throw new InvalidOperationException("No se pudo iniciar el controlador Sync.");
            string output = await process.StandardOutput.ReadToEndAsync();
            string error = await process.StandardError.ReadToEndAsync();
            await process.WaitForExitAsync();

            string combined = string.Join(Environment.NewLine, new[] { output.Trim(), error.Trim() }.Where(x => !string.IsNullOrWhiteSpace(x)));
            ApplyStatusOutput(combined, action);

            if (process.ExitCode != 0 && !string.IsNullOrWhiteSpace(combined))
            {
                Message = FriendlyError(combined);
            }
            else if (action == "start")
            {
                Message = "Worker iniciado o ya estaba activo.";
                UpdateEffectiveSchedule();
            }
            else if (action == "stop")
            {
                Message = "Worker detenido.";
            }
            else if (action == "run")
            {
                Message = "Sincronizacion manual ejecutada.";
            }
        }
        catch (Exception exception)
        {
            Message = $"No se pudo ejecutar Sync. {exception.Message}";
            Status = "Error";
        }
        finally
        {
            IsBusy = false;
        }
    }

    private void ApplyStatusOutput(string output, string action)
    {
        if (string.IsNullOrWhiteSpace(output))
        {
            StatusDetail = "Sin respuesta del controlador.";
            return;
        }

        StatusDetail = output;

        if (output.Contains("ACTIVO", StringComparison.OrdinalIgnoreCase))
        {
            Status = "Activo";
            StatusDetail = $"La sincronizacion esta activa en segundo plano. {BuildIntervalMessage()}";
            return;
        }

        if (output.Contains("DETENIDO", StringComparison.OrdinalIgnoreCase))
        {
            Status = "Detenido";
            StatusDetail = "La sincronizacion esta detenida. Puedes iniciarla cuando quieras comenzar a enviar y recibir cambios.";
            return;
        }

        if (output.Contains("Worker iniciado", StringComparison.OrdinalIgnoreCase))
        {
            Status = "Activo";
            StatusDetail = $"Worker iniciado correctamente. {BuildIntervalMessage()}";
            return;
        }

        if (output.Contains("Worker detenido", StringComparison.OrdinalIgnoreCase) || output.Contains("No hay worker activo", StringComparison.OrdinalIgnoreCase))
        {
            Status = "Detenido";
            StatusDetail = "Worker detenido. No se sincronizaran cambios hasta iniciarlo nuevamente.";
            return;
        }

        if (output.Contains("Sincronizacion ejecutada", StringComparison.OrdinalIgnoreCase))
        {
            Status = "Sincronizado";
            StatusDetail = SummarizeRunOutput(output);
            return;
        }

        if (action == "status")
        {
            StatusDetail = "No se pudo interpretar el estado del worker. Revisa el mensaje inferior o el log.";
        }
    }

    private static string SummarizeRunOutput(string output)
    {
        string[] interestingPrefixes =
        [
            "Eventos subidos:",
            "Duplicados en nube:",
            "Eventos bajados:",
            "Eventos confirmados:",
            "Eventos aplicados:",
            "Eventos ignorados:",
            "Fallos:",
        ];

        string[] lines = output
            .Split([Environment.NewLine, "\n"], StringSplitOptions.RemoveEmptyEntries)
            .Select(line => line.Trim())
            .Where(line => interestingPrefixes.Any(prefix => line.StartsWith(prefix, StringComparison.OrdinalIgnoreCase)))
            .ToArray();

        if (lines.Length == 0)
        {
            return "Sincronizacion manual ejecutada. Revisa el log para ver el detalle.";
        }

        return "Sincronizacion manual completada. " + string.Join(" | ", lines);
    }

    private void LoadLogTail()
    {
        string? root = FindRepoRoot();
        if (root is null)
        {
            LastLog = "No se encontro la raiz del proyecto.";
            return;
        }

        string tenantLogName = $"sync-worker-{SafeFilePart(TenantSlug)}.log";
        string logPath = Path.Combine(root, "storage", "logs", tenantLogName);
        if (!File.Exists(logPath))
        {
            logPath = Path.Combine(root, "storage", "logs", "sync-worker.log");
        }

        if (!File.Exists(logPath))
        {
            LastLog = "Aun no existe log del worker.";
            return;
        }

        string[] lines = File.ReadAllLines(logPath);
        LastLog = string.Join(Environment.NewLine, lines.TakeLast(80));
    }

    private string ResolveScriptPath()
    {
        string? root = FindRepoRoot();
        if (root is null)
        {
            return "";
        }

        string path = Path.Combine(root, "scripts", "sync-worker.cmd");
        return File.Exists(path) ? path : "";
    }

    private static string? FindRepoRoot()
    {
        DirectoryInfo? current = new(AppContext.BaseDirectory);
        while (current is not null)
        {
            string candidate = Path.Combine(current.FullName, "artisan");
            if (File.Exists(candidate))
            {
                return current.FullName;
            }

            current = current.Parent;
        }

        current = new DirectoryInfo(Environment.CurrentDirectory);
        while (current is not null)
        {
            string candidate = Path.Combine(current.FullName, "artisan");
            if (File.Exists(candidate))
            {
                return current.FullName;
            }

            current = current.Parent;
        }

        return null;
    }

    private static string QuoteForCmd(string value)
    {
        return "\"" + value.Replace("\"", "\"\"") + "\"";
    }

    private static string BuildInstallationCode()
    {
        string machineName = Environment.MachineName.ToUpperInvariant();
        char[] clean = machineName
            .Select(character => char.IsLetterOrDigit(character) ? character : '-')
            .ToArray();

        return $"LOCAL-{new string(clean)}";
    }

    private static string SafeFilePart(string value)
    {
        string safe = new(value.ToLowerInvariant().Select(character =>
            char.IsLetterOrDigit(character) || character is '-' or '_' ? character : '-').ToArray());

        return string.IsNullOrWhiteSpace(safe) ? "default" : safe;
    }

    private static string FriendlyError(string output)
    {
        if (output.Contains("is not recognized as an internal or external command", StringComparison.OrdinalIgnoreCase))
        {
            return "No se pudo ejecutar el controlador de sincronizacion. Verifica que el archivo scripts\\sync-worker.cmd exista y vuelve a intentarlo.";
        }

        if (output.Contains("could not open input file", StringComparison.OrdinalIgnoreCase))
        {
            return "No se pudo iniciar Laravel para sincronizar. Verifica que el proyecto tenga artisan y que PHP este disponible.";
        }

        if (output.Contains("Falta CloudUrl", StringComparison.OrdinalIgnoreCase))
        {
            return "Falta configurar la URL de la nube. Escribela en opciones avanzadas o define SYNC_CLOUD_URL en el .env.";
        }

        if (output.Contains("Falta Token", StringComparison.OrdinalIgnoreCase))
        {
            return "Falta configurar el token de la nube para esta empresa. Entra a Sincronizacion, pega el token, guarda la configuracion y vuelve a sincronizar.";
        }

        if (output.Contains("Connection refused", StringComparison.OrdinalIgnoreCase) ||
            output.Contains("No connection could be made", StringComparison.OrdinalIgnoreCase))
        {
            return "No se pudo conectar con la nube. Revisa la URL, el token o la conexion de red.";
        }

        return output;
    }

    private void LoadLocalConfiguration()
    {
        SyncWorkerConfigurationFile file = LoadConfigurationFile();
        if (!file.Tenants.TryGetValue(TenantSlug, out SyncTenantConfiguration? config))
        {
            ConfigurationStatus = "Esta empresa aun no tiene configuracion local guardada.";
            return;
        }

        InstallationCode = string.IsNullOrWhiteSpace(config.InstallationCode) ? InstallationCode : config.InstallationCode;
        NodeCode = string.IsNullOrWhiteSpace(config.NodeCode) ? NodeCode : config.NodeCode;
        NodeName = string.IsNullOrWhiteSpace(config.NodeName) ? NodeName : config.NodeName;
        CloudUrl = config.CloudUrl ?? "";
        Token = config.Token ?? "";
        Interval = config.Interval >= 5 ? config.Interval.ToString() : "30";
        ConfigurationStatus = string.IsNullOrWhiteSpace(Token)
            ? "Configuracion local encontrada. Token pendiente o tomado desde .env."
            : "Configuracion local encontrada. Esta empresa tiene token propio.";
    }

    private string LoadConfiguredCloudUrl()
    {
        SyncWorkerConfigurationFile file = LoadConfigurationFile();
        return file.Tenants.TryGetValue(TenantSlug, out SyncTenantConfiguration? config) ? config.CloudUrl ?? "" : "";
    }

    private string LoadConfiguredToken()
    {
        SyncWorkerConfigurationFile file = LoadConfigurationFile();
        return file.Tenants.TryGetValue(TenantSlug, out SyncTenantConfiguration? config) ? config.Token ?? "" : "";
    }

    private void UpdateEffectiveSchedule()
    {
        EffectiveSchedule = $"Automatico: al iniciar, sincroniza cada {NormalizeInterval()} segundos.";
    }

    private string BuildIntervalMessage()
    {
        return $"Ciclo automatico cada {NormalizeInterval()} segundos.";
    }

    private int NormalizeInterval()
    {
        if (!int.TryParse(Interval, out int parsed) || parsed < 5)
        {
            return 30;
        }

        return parsed;
    }

    private static SyncWorkerConfigurationFile LoadConfigurationFile()
    {
        string? root = FindRepoRoot();
        if (root is null)
        {
            return new SyncWorkerConfigurationFile();
        }

        string path = ConfigurationPath(root);
        if (!File.Exists(path))
        {
            return new SyncWorkerConfigurationFile();
        }

        try
        {
            return JsonSerializer.Deserialize<SyncWorkerConfigurationFile>(File.ReadAllText(path)) ?? new SyncWorkerConfigurationFile();
        }
        catch
        {
            return new SyncWorkerConfigurationFile();
        }
    }

    private static void SaveConfigurationFile(SyncWorkerConfigurationFile file)
    {
        string? root = FindRepoRoot();
        if (root is null)
        {
            throw new InvalidOperationException("No se encontro la raiz del proyecto.");
        }

        string path = ConfigurationPath(root);
        Directory.CreateDirectory(Path.GetDirectoryName(path)!);
        File.WriteAllText(path, JsonSerializer.Serialize(file, new JsonSerializerOptions
        {
            WriteIndented = true,
        }));
    }

    private static string ConfigurationPath(string root)
    {
        return Path.Combine(root, "storage", "app", "sync-worker", "sync-config.json");
    }
}

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

public sealed record SyncStatusResponse(
    [property: JsonPropertyName("data")] SyncStatusData? Data);

public sealed record SyncStatusData(
    [property: JsonPropertyName("nodes")] int Nodes,
    [property: JsonPropertyName("outbox")] SyncStatusOutbox? Outbox,
    [property: JsonPropertyName("inbox")] SyncStatusInbox? Inbox,
    [property: JsonPropertyName("latest_events")] SyncStatusLatestEvents? LatestEvents);

public sealed record SyncStatusOutbox(
    [property: JsonPropertyName("pending")] int Pending,
    [property: JsonPropertyName("processed")] int Processed,
    [property: JsonPropertyName("failed")] int Failed);

public sealed record SyncStatusInbox(
    [property: JsonPropertyName("received")] int Received,
    [property: JsonPropertyName("applied")] int Applied,
    [property: JsonPropertyName("failed")] int Failed);

public sealed record SyncStatusLatestEvents(
    [property: JsonPropertyName("outbox")] IReadOnlyList<SyncStatusEvent>? Outbox,
    [property: JsonPropertyName("inbox")] IReadOnlyList<SyncStatusEvent>? Inbox);

public sealed record SyncStatusEvent(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("event_type")] string EventType,
    [property: JsonPropertyName("aggregate_type")] string AggregateType,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("occurred_at")] string? OccurredAt,
    [property: JsonPropertyName("received_at")] string? ReceivedAt);

public sealed record SyncReadinessResponse(
    [property: JsonPropertyName("data")] SyncReadinessData? Data);

public sealed record SyncReadinessData(
    [property: JsonPropertyName("installation_code")] string InstallationCode,
    [property: JsonPropertyName("node_code")] string? NodeCode,
    [property: JsonPropertyName("node_name")] string? NodeName,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("last_push_at")] string? LastPushAt,
    [property: JsonPropertyName("last_pull_at")] string? LastPullAt,
    [property: JsonPropertyName("last_apply_at")] string? LastApplyAt,
    [property: JsonPropertyName("last_success_at")] string? LastSuccessAt,
    [property: JsonPropertyName("initial_sync_completed_at")] string? InitialSyncCompletedAt,
    [property: JsonPropertyName("last_error")] string? LastError);
