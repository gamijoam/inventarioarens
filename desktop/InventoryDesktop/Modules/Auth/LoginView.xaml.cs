using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;
using InventoryDesktop.Core.Security;

namespace InventoryDesktop.Modules.Auth;

public partial class LoginView : UserControl
{
    private readonly LoginViewModel viewModel = new();
    private readonly DispatcherTimer emailLookupTimer = new() { Interval = TimeSpan.FromMilliseconds(550) };

    public LoginView()
    {
        InitializeComponent();
        DataContext = viewModel;
        viewModel.LoginSucceeded += (_, session) => LoginSucceeded?.Invoke(this, session);
        emailLookupTimer.Tick += EmailLookupTimer_Tick;
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

    private void EmailInput_TextChanged(object sender, TextChangedEventArgs e)
    {
        emailLookupTimer.Stop();
        emailLookupTimer.Start();
    }

    private async void EmailLookupTimer_Tick(object? sender, EventArgs e)
    {
        emailLookupTimer.Stop();
        await viewModel.FindTenantsByEmailAsync();
    }
}
