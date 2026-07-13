using System.ComponentModel;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Config;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Core.Services;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop.Modules.Auth;

public partial class ProgrammerLoginWindow : Window, INotifyPropertyChanged
{
    public event PropertyChangedEventHandler? PropertyChanged;

    private readonly ApiClient apiClient = new();
    private readonly TokenVault tokenVault = new();
    private string email = "";
    private bool isBusy;
    private string statusMessage = "";

    public ProgrammerLoginWindow()
    {
        InitializeComponent();
        DataContext = this;
        Loaded += (_, _) =>
        {
            string apiBaseUrl = ResolveApiBaseUrl();
            ApiBaseUrlDisplay.Text = apiBaseUrl;
            EmailInput.Focus();
        };
        EmailInput.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        PasswordInput.PasswordChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
    }

    public DesktopSession? ResultSession { get; private set; }

    public bool IsBusy
    {
        get => isBusy;
        private set
        {
            if (SetProperty(ref isBusy, value))
            {
                RaisePropertyChanged(nameof(CanAccept));
            }
        }
    }

    public string Email
    {
        get => email;
        private set
        {
            if (SetProperty(ref email, value))
            {
                RaisePropertyChanged(nameof(CanAccept));
            }
        }
    }

    public string StatusMessage
    {
        get => statusMessage;
        private set
        {
            SetProperty(ref statusMessage, value);
            StatusBar.Text = value;
        }
    }

    public bool CanAccept =>
        !IsBusy
        && !string.IsNullOrWhiteSpace(Email)
        && EmailInput?.Text.Contains('@') == true
        && !string.IsNullOrEmpty(PasswordInput?.Password);

    private string ResolveApiBaseUrl()
    {
        PersistedConfig? config = ConfigStore.TryRead();
        if (config is null)
        {
            return PersistedConfig.DefaultApiBaseUrl;
        }

        if (!string.IsNullOrWhiteSpace(config.PlatformApiBaseUrl))
        {
            return config.PlatformApiBaseUrl!;
        }

        return !string.IsNullOrWhiteSpace(config.ApiBaseUrl)
            ? config.ApiBaseUrl
            : PersistedConfig.DefaultApiBaseUrl;
    }

    private async void Accept_Click(object sender, RoutedEventArgs e)
    {
        if (!CanAccept)
        {
            return;
        }

        IsBusy = true;
        Email = EmailInput.Text.Trim().ToLowerInvariant();
        string password = PasswordInput.Password;
        string apiBaseUrl = ResolveApiBaseUrl();

        try
        {
            apiClient.Configure(apiBaseUrl);
            LoginResponse response = await apiClient.PostAsync<LoginRequest, LoginResponse>(
                "auth/platform-login",
                new LoginRequest(Email, password, Environment.MachineName));

            tokenVault.Save(response.Data.Token);
            apiClient.Configure(apiBaseUrl, response.Data.Token, response.Data.Tenant?.Slug);
            StatusMessage = $"Sesion iniciada como {response.Data.User.Name}.";

            var session = new DesktopSession(apiClient, response.Data, apiBaseUrl);
            new SessionService(apiClient, tokenVault).PersistSession(session);
            ResultSession = session;
            DialogResult = true;
            Close();
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
        }
        catch (HttpRequestException)
        {
            StatusMessage = $"No se pudo conectar con {apiBaseUrl}. Verifica que el servidor este accesible.";
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

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private bool SetProperty<T>(ref T field, T value, [CallerMemberName] string? name = null)
    {
        if (EqualityComparer<T>.Default.Equals(field, value))
        {
            return false;
        }
        field = value;
        RaisePropertyChanged(name);
        return true;
    }

    private void RaisePropertyChanged([CallerMemberName] string? propertyName = null)
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
    }
}
