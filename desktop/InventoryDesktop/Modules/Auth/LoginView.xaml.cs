using System.Windows;
using System.Windows.Controls;
using System.Windows.Threading;
using InventoryDesktop.Core.Security;
using MaterialDesignThemes.Wpf;

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
        Loaded += (_, _) => EmailInput.Focus();
    }

    public event EventHandler<DesktopSession>? LoginSucceeded;

    public void ShowError(string message)
    {
        viewModel.SetError(message);
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

    private bool isPasswordVisible;

    private void TogglePassword_Click(object sender, RoutedEventArgs e)
    {
        isPasswordVisible = !isPasswordVisible;
        if (isPasswordVisible)
        {
            PasswordVisibleInput.Text = PasswordInput.Password;
            PasswordVisibleInput.Visibility = Visibility.Visible;
            PasswordInput.Visibility = Visibility.Collapsed;
            TogglePasswordIcon.Kind = PackIconKind.EyeOffOutline;
            PasswordVisibleInput.Focus();
            PasswordVisibleInput.CaretIndex = PasswordVisibleInput.Text.Length;
        }
        else
        {
            PasswordInput.Password = PasswordVisibleInput.Text;
            PasswordVisibleInput.Visibility = Visibility.Collapsed;
            PasswordInput.Visibility = Visibility.Visible;
            TogglePasswordIcon.Kind = PackIconKind.EyeOutline;
            PasswordInput.Focus();
        }
    }

    private void PasswordInput_PasswordChanged(object sender, RoutedEventArgs e)
    {
        if (!isPasswordVisible && PasswordVisibleInput.Text != PasswordInput.Password)
        {
            PasswordVisibleInput.Text = PasswordInput.Password;
        }
    }

    private void PasswordVisibleInput_TextChanged(object sender, TextChangedEventArgs e)
    {
        if (isPasswordVisible && PasswordInput.Password != PasswordVisibleInput.Text)
        {
            PasswordInput.Password = PasswordVisibleInput.Text;
        }
    }
}
