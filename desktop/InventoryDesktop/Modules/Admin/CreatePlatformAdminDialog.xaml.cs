using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;

namespace InventoryDesktop.Modules.Admin;

public partial class CreatePlatformAdminDialog : Window, INotifyPropertyChanged
{
    public event PropertyChangedEventHandler? PropertyChanged;

    public CreatePlatformAdminDialog()
    {
        InitializeComponent();
        DataContext = this;
        NameBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        EmailBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
    }

    public bool CanAccept =>
        !string.IsNullOrWhiteSpace(NameBox?.Text)
        && !string.IsNullOrWhiteSpace(EmailBox?.Text)
        && EmailBox?.Text.Contains('@') == true;

    public CreatePlatformAdminRequest BuildRequest()
    {
        string? password = string.IsNullOrWhiteSpace(PasswordBox?.Password) ? null : PasswordBox.Password;
        return new CreatePlatformAdminRequest(
            Name: NameBox.Text.Trim(),
            Email: EmailBox.Text.Trim().ToLowerInvariant(),
            Password: password);
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private void Accept_Click(object sender, RoutedEventArgs e)
    {
        if (!CanAccept)
        {
            return;
        }
        DialogResult = true;
        Close();
    }

    private void RaisePropertyChanged([CallerMemberName] string? propertyName = null)
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
    }
}