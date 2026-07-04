using System.Windows;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop;

public partial class MainWindow : Window
{
    private readonly LoginView loginView = new();

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
            Width = 1360;
            Height = 820;
            MinWidth = 1120;
            MinHeight = 720;
            WindowStartupLocation = WindowStartupLocation.CenterScreen;
            AppContent.Content = new ShellView(session);
        }
        catch (Exception exception)
        {
            loginView.ShowError($"No se pudo abrir el panel principal. {exception.Message}");
        }
    }
}
