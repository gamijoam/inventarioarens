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

    private async void FindTenants_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.FindTenantsAsync(PasswordInput.Password);
    }

    private async void Login_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.LoginAsync(PasswordInput.Password);
    }

    private void LoginSucceeded(object? sender, DesktopSession session)
    {
        Hide();
        ShellWindow shell = new(session);
        Application.Current.MainWindow = shell;
        shell.Closed += (_, _) => Application.Current.Shutdown();
        shell.Show();
        Close();
    }
}
