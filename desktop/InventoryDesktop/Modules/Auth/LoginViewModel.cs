using System.Collections.ObjectModel;
using System.Net.Http;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.Auth;

public sealed class LoginViewModel : ViewModelBase
{
    private readonly ApiClient apiClient = new();
    private readonly TokenVault tokenVault = new();
    private string apiBaseUrl = "http://127.0.0.1:8000/api/";
    private string email = "";
    private TenantOption? selectedTenant;
    private string statusMessage = "";
    private bool isBusy;

    public event EventHandler<DesktopSession>? LoginSucceeded;

    public string ApiBaseUrl
    {
        get => apiBaseUrl;
        set => SetProperty(ref apiBaseUrl, value);
    }

    public string Email
    {
        get => email;
        set => SetProperty(ref email, value);
    }

    public ObservableCollection<TenantOption> Tenants { get; } = new();

    public TenantOption? SelectedTenant
    {
        get => selectedTenant;
        set => SetProperty(ref selectedTenant, value);
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public bool IsBusy
    {
        get => isBusy;
        set => SetProperty(ref isBusy, value);
    }

    public async Task FindTenantsByEmailAsync()
    {
        if (!ValidateEmailForTenantLookup())
        {
            return;
        }

        await RunAsync(async () =>
        {
            await FetchTenantsAsync();
            StatusMessage = Tenants.Count == 0
                ? "No hay empresas activas para este usuario."
                : Tenants.Count == 1
                    ? $"Empresa encontrada: {Tenants[0].Name}."
                    : $"Selecciona una de las {Tenants.Count} empresas disponibles.";
        });
    }

    public async Task LoginAsync(string password)
    {
        if (!ValidateCredentials(password))
        {
            return;
        }

        if (SelectedTenant is null)
        {
            await RunAsync(async () =>
            {
                await FetchTenantsAsync();
                if (Tenants.Count != 1)
                {
                    StatusMessage = Tenants.Count == 0
                        ? "No hay empresas activas para este usuario."
                        : "Selecciona la empresa antes de ingresar.";
                    return;
                }

                await LoginWithSelectedTenantAsync(password);
            });

            return;
        }

        await RunAsync(() => LoginWithSelectedTenantAsync(password));
    }

    private async Task FetchTenantsAsync()
    {
        apiClient.Configure(ApiBaseUrl);
        TenantLookupResponse response = await apiClient.PostAsync<TenantLookupRequest, TenantLookupResponse>(
            "auth/tenants",
            new TenantLookupRequest(Email.Trim()));

        Tenants.Clear();
        foreach (TenantOption tenant in response.Data)
        {
            Tenants.Add(tenant);
        }

        SelectedTenant = Tenants.FirstOrDefault();
    }

    private async Task LoginWithSelectedTenantAsync(string password)
    {
        if (SelectedTenant is null)
        {
            StatusMessage = "Selecciona la empresa antes de ingresar.";
            return;
        }

        apiClient.Configure(ApiBaseUrl, tenantSlug: SelectedTenant.Slug);
        LoginResponse response = await apiClient.PostAsync<LoginRequest, LoginResponse>(
            "auth/login",
            new LoginRequest(Email.Trim(), password, Environment.MachineName));

        tokenVault.Save(response.Data.Token);
        apiClient.Configure(ApiBaseUrl, response.Data.Token, response.Data.Tenant.Slug);
        StatusMessage = $"Sesion iniciada en {response.Data.Tenant.Name} como {response.Data.User.Name}.";
        LoginSucceeded?.Invoke(this, new DesktopSession(apiClient, response.Data, ApiBaseUrl));
    }

    private bool ValidateCredentials(string password)
    {
        if (string.IsNullOrWhiteSpace(ApiBaseUrl))
        {
            StatusMessage = "Debes indicar la URL base de la API.";
            return false;
        }

        if (string.IsNullOrWhiteSpace(Email))
        {
            StatusMessage = "El correo es obligatorio.";
            return false;
        }

        if (string.IsNullOrWhiteSpace(password))
        {
            StatusMessage = "La contrasena es obligatoria.";
            return false;
        }

        return true;
    }

    private bool ValidateEmailForTenantLookup()
    {
        Tenants.Clear();
        SelectedTenant = null;

        if (string.IsNullOrWhiteSpace(ApiBaseUrl))
        {
            StatusMessage = "Debes indicar la URL base de la API.";
            return false;
        }

        if (string.IsNullOrWhiteSpace(Email))
        {
            StatusMessage = "";
            return false;
        }

        string normalizedEmail = Email.Trim();
        if (!normalizedEmail.Contains('@') || !normalizedEmail.Contains('.'))
        {
            StatusMessage = "";
            return false;
        }

        return true;
    }

    private async Task RunAsync(Func<Task> action)
    {
        try
        {
            IsBusy = true;
            StatusMessage = "Conectando con el servidor...";
            await action();
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API. Verifica que Laravel este encendido.";
        }
        catch (TaskCanceledException)
        {
            StatusMessage = "La conexion tardo demasiado. Intenta nuevamente.";
        }
        finally
        {
            IsBusy = false;
        }
    }
}
