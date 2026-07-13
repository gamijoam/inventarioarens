using System.Windows;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.Admin;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop;

public partial class MainWindow : Window
{
    public MainWindow()
    {
        InitializeComponent();
        AppLogger.Info("MainWindow creada.");
        var loginView = new LoginView();
        AppContent.Content = loginView;
        loginView.LoginSucceeded += (_, session) => OnLoginSucceeded(loginView, session);
        loginView.PlatformAdminLoginSucceeded += (_, session) => OnPlatformAdminLoginSucceeded(loginView, session);
        Closed += (_, _) =>
        {
            AppLogger.Info("MainWindow cerrada por el usuario. Apagando aplicacion.");
            Application.Current.Shutdown();
        };
    }

    private void OnLoginSucceeded(LoginView loginView, DesktopSession session)
    {
        try
        {
            AppLogger.Info($"Login correcto para tenant '{session.TenantName}'. Reemplazando LoginView por ShellView.");

            var shellView = new ShellView(session);
            AppContent.Content = shellView;

            Title = $"Sistema de Inventario - {session.TenantName}";
            AppLogger.Info("ShellView mostrada en MainWindow. LoginView descartada.");
        }
        catch (Exception exception)
        {
            AppLogger.Error("No se pudo cargar ShellView.", exception);
            loginView.ShowError($"No se pudo abrir el panel principal. {exception.Message}");
        }
    }

    private void OnPlatformAdminLoginSucceeded(LoginView loginView, DesktopSession session)
    {
        try
        {
            AppLogger.Info("Login de Platform Admin correcto. Abriendo SaaS Master.");

            var adminClient = new ApiClient();
            adminClient.Configure(session.ApiBaseUrl, session.Login.Token, tenantSlug: null);

            var saasMaster = new SaasMasterWindow(adminClient)
            {
                Owner = this,
            };
            saasMaster.Closed += (_, _) =>
            {
                Title = "Sistema de Inventario";
                var newLogin = new LoginView();
                AppContent.Content = newLogin;
                newLogin.LoginSucceeded += (_, s) => OnLoginSucceeded(newLogin, s);
                newLogin.PlatformAdminLoginSucceeded += (_, s) => OnPlatformAdminLoginSucceeded(newLogin, s);
            };
            saasMaster.Show();

            Title = $"Sistema de Inventario - Modo Programador ({session.UserName})";
            AppLogger.Info("SaaS Master abierta en MainWindow.");
        }
        catch (Exception exception)
        {
            AppLogger.Error("No se pudo abrir el panel SaaS Master.", exception);
            loginView.ShowError($"No se pudo abrir el panel de programador. {exception.Message}");
        }
    }
}
