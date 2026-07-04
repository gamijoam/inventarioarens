using System.Windows;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop;

public partial class MainWindow : Window
{
    private readonly LoginViewModel viewModel = new();

    public MainWindow()
    {
        InitializeComponent();
        DataContext = viewModel;
        viewModel.LoginSucceeded += LoginSucceeded;
    }

    private async void Login_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.LoginAsync(PasswordInput.Password);
    }

    private void LoginSucceeded(object? sender, DesktopSession session)
    {
        try
        {
            Hide();

            ShellWindow shell = new(session);
            Application.Current.MainWindow = shell;
            shell.Closed += (_, _) => Application.Current.Shutdown();
            shell.Show();
            shell.Activate();
        }
        catch (Exception exception)
        {
            Show();
            MessageBox.Show(
                $"No se pudo abrir el panel principal.\n\n{exception.Message}",
                "Sistema de Inventario",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
        }
    }
}
