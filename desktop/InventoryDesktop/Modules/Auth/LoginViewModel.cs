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
    private bool hasError;

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
        private set
        {
            if (SetProperty(ref statusMessage, value))
            {
                RaisePropertyChanged(nameof(HasStatusInfo));
            }
        }
    }

    public bool IsBusy
    {
        get => isBusy;
        private set
        {
            if (SetProperty(ref isBusy, value))
            {
                RaisePropertyChanged(nameof(IsLoginEnabled));
            }
        }
    }

    public bool HasError
    {
        get => hasError;
        private set
        {
            if (SetProperty(ref hasError, value))
            {
                RaisePropertyChanged(nameof(HasStatusInfo));
            }
        }
    }

    public bool HasStatusInfo => !string.IsNullOrWhiteSpace(StatusMessage);

    public bool IsLoginEnabled => !IsBusy;

    public void SetError(string message)
    {
        StatusMessage = message;
        HasError = true;
    }

    private void SetInfo(string message)
    {
        StatusMessage = message;
        HasError = false;
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
            if (Tenants.Count == 0)
            {
                SetInfo("No hay empresas activas para este correo. Verifica el servidor API o que la cuenta exista.");
            }
            else if (Tenants.Count == 1)
            {
                SetInfo($"Empresa encontrada: {Tenants[0].Name}.");
            }
            else
            {
                SetInfo($"Selecciona una de las {Tenants.Count} empresas disponibles.");
            }
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
                    HasError = true;
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
            HasError = true;
            return;
        }

        apiClient.Configure(ApiBaseUrl, tenantSlug: SelectedTenant.Slug);
        LoginResponse response = await apiClient.PostAsync<LoginRequest, LoginResponse>(
            "auth/login",
            new LoginRequest(Email.Trim(), password, Environment.MachineName));

        tokenVault.Save(response.Data.Token);
        apiClient.Configure(ApiBaseUrl, response.Data.Token, response.Data.Tenant.Slug);
        StatusMessage = $"Sesión iniciada en {response.Data.Tenant.Name} como {response.Data.User.Name}.";
        HasError = false;
        LoginSucceeded?.Invoke(this, new DesktopSession(apiClient, response.Data, ApiBaseUrl));
    }

    private bool ValidateCredentials(string password)
    {
        if (string.IsNullOrWhiteSpace(ApiBaseUrl))
        {
            SetError("Debes indicar la URL base de la API.");
            return false;
        }

        if (string.IsNullOrWhiteSpace(Email))
        {
            SetError("El correo es obligatorio.");
            return false;
        }

        if (string.IsNullOrWhiteSpace(password))
        {
            SetError("La contraseña es obligatoria.");
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
            SetError("Debes indicar la URL base de la API.");
            return false;
        }

        if (string.IsNullOrWhiteSpace(Email))
        {
            SetInfo(string.Empty);
            return false;
        }

        string normalizedEmail = Email.Trim();
        if (!normalizedEmail.Contains('@') || !normalizedEmail.Contains('.'))
        {
            SetInfo(string.Empty);
            return false;
        }

        return true;
    }

    private async Task RunAsync(Func<Task> action)
    {
        try
        {
            IsBusy = true;
            SetInfo("Conectando con el servidor...");
            await action();
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError($"No se pudo conectar con {ApiBaseUrl}. Verifica el servidor API o tu conexión.");
        }
        catch (TaskCanceledException)
        {
            SetError("La conexión tardó demasiado. Intenta nuevamente.");
        }
        finally
        {
            IsBusy = false;
        }
    }
}