using System.Windows;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop;

public partial class MainWindow : Window
{
    private readonly LoginView loginView = new();
    private ShellWindow? shellWindow;

    public MainWindow()
    {
        InitializeComponent();
        AppLogger.Info("MainWindow creada.");
        AppContent.Content = loginView;
        loginView.LoginSucceeded += LoginSucceeded;
        Closed += (_, _) =>
        {
            AppLogger.Info("MainWindow cerrada por el usuario. Apagando aplicacion.");
            Application.Current.Shutdown();
        };
    }

    private void LoginSucceeded(object? sender, DesktopSession session)
    {
        try
        {
            AppLogger.Info($"Login correcto para tenant '{session.TenantName}'. Abriendo ShellWindow.");
            if (shellWindow is { IsVisible: true })
            {
                shellWindow.Activate();
                loginView.ShowError("El panel principal ya esta abierto.");
                AppLogger.Info("ShellWindow ya estaba abierta; se activo la ventana existente.");
                return;
            }

            shellWindow = new ShellWindow();
            shellWindow.Closed += (_, _) => shellWindow = null;
            shellWindow.Show();
            shellWindow.Activate();
            AppLogger.Info("ShellWindow mostrada. Cargando contenido del panel.");
            shellWindow.LoadSession(session);
            AppLogger.Info("ShellWindow cargo el panel principal correctamente.");
            loginView.ShowError("Sesion iniciada. El panel principal se abrio en otra ventana.");
        }
        catch (Exception exception)
        {
            AppLogger.Error("No se pudo abrir el panel principal.", exception);
            loginView.ShowError($"No se pudo abrir el panel principal. {exception.Message}");
        }
    }
}
