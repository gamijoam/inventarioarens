using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;

namespace InventoryDesktop.Modules.Admin;

public partial class EditPlatformAdminDialog : Window, INotifyPropertyChanged
{
    public event PropertyChangedEventHandler? PropertyChanged;

    public EditPlatformAdminDialog(PlatformAdminResource admin, long currentAdminId)
    {
        InitializeComponent();
        DataContext = this;
        NameBox.Text = admin.Name;
        EmailBox.Text = admin.Email;
        IsPlatformAdminBox.IsChecked = admin.IsPlatformAdmin;
        IsSelf = admin.Id == currentAdminId;

        NameBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        EmailBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        IsPlatformAdminBox.Checked += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        IsPlatformAdminBox.Unchecked += (_, _) => RaisePropertyChanged(nameof(CanAccept));

        if (IsSelf && !admin.IsPlatformAdmin)
        {
            SelfDemoteWarning.Visibility = Visibility.Visible;
        }
    }

    public bool IsSelf { get; }

    public bool CanAccept =>
        !string.IsNullOrWhiteSpace(NameBox?.Text)
        && !string.IsNullOrWhiteSpace(EmailBox?.Text)
        && EmailBox?.Text.Contains('@') == true
        && IsPlatformAdminBox?.IsChecked is not null;

    public UpdatePlatformAdminRequest BuildRequest()
    {
        return new UpdatePlatformAdminRequest(
            Name: NameBox.Text.Trim(),
            Email: EmailBox.Text.Trim().ToLowerInvariant(),
            IsPlatformAdmin: IsPlatformAdminBox.IsChecked == true);
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