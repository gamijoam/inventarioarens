using System.Windows;
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
        AppContent.Content = loginView;
        loginView.LoginSucceeded += LoginSucceeded;
    }

    private void LoginSucceeded(object? sender, DesktopSession session)
    {
        try
        {
            if (shellWindow is { IsVisible: true })
            {
                shellWindow.Activate();
                loginView.ShowError("El panel principal ya esta abierto.");
                return;
            }

            shellWindow = new ShellWindow(session)
            {
                Owner = this
            };
            shellWindow.Closed += (_, _) => shellWindow = null;
            shellWindow.Show();
            shellWindow.Activate();
            loginView.ShowError("Sesion iniciada. El panel principal se abrio en otra ventana.");
        }
        catch (Exception exception)
        {
            loginView.ShowError($"No se pudo abrir el panel principal. {exception.Message}");
        }
    }
}
