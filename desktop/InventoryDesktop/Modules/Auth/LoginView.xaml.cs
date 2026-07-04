using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Security;

namespace InventoryDesktop.Modules.Auth;

public partial class LoginView : UserControl
{
    private readonly LoginViewModel viewModel = new();

    public LoginView()
    {
        InitializeComponent();
        DataContext = viewModel;
        viewModel.LoginSucceeded += (_, session) => LoginSucceeded?.Invoke(this, session);
    }

    public event EventHandler<DesktopSession>? LoginSucceeded;

    public void ShowError(string message)
    {
        viewModel.StatusMessage = message;
    }

    private async void Login_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.LoginAsync(PasswordInput.Password);
    }
}
