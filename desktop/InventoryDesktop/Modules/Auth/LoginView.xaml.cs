using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Threading;
using InventoryDesktop.Core.Config;
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
        viewModel.PlatformAdminLoginSucceeded += (_, session) => PlatformAdminLoginSucceeded?.Invoke(this, session);
        emailLookupTimer.Tick += EmailLookupTimer_Tick;
        Loaded += (_, _) =>
        {
            viewModel.ResolveApiBaseUrl();
            EmailInput.Focus();
        };
        PreviewKeyDown += LoginView_PreviewKeyDown;
        PreviewKeyDown += LoginView_Hotkeys;
    }

    public event EventHandler<DesktopSession>? LoginSucceeded;

    public event EventHandler<DesktopSession>? PlatformAdminLoginSucceeded;

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

    private async void LoginView_PreviewKeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        if (e.Key == System.Windows.Input.Key.Enter)
        {
            e.Handled = true;
            await viewModel.LoginAsync(PasswordInput.Password);
        }
    }

    private async void LoginView_Hotkeys(object sender, KeyEventArgs e)
    {
        if (e.KeyboardDevice.Modifiers != (ModifierKeys.Control | ModifierKeys.Shift) || e.Key != Key.P)
        {
            return;
        }

        PersistedConfig? config = ConfigStore.TryRead();
        if (config is null || !config.AllowProgrammerMode)
        {
            return;
        }

        e.Handled = true;
        await OpenProgrammerLoginAsync();
    }

    private async System.Threading.Tasks.Task OpenProgrammerLoginAsync()
    {
        Window? owner = Window.GetWindow(this);
        if (owner is null)
        {
            return;
        }

        var programmer = new ProgrammerLoginWindow { Owner = owner };
        bool? result = programmer.ShowDialog();
        if (result == true && programmer.ResultSession is { } session)
        {
            viewModel.OpenPlatformAdminSession(session);
        }
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
