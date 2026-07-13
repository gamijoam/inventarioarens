using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Controls;

namespace InventoryDesktop.Modules.Admin;

public partial class CreateGroupDialog : Window, INotifyPropertyChanged
{
    public event PropertyChangedEventHandler? PropertyChanged;

    public CreateGroupDialog()
    {
        InitializeComponent();
        DataContext = this;
        GroupNameBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        GroupSlugBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        OwnerNameBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        OwnerEmailBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
    }

    public bool CanAccept =>
        !string.IsNullOrWhiteSpace(GroupNameBox?.Text)
        && !string.IsNullOrWhiteSpace(GroupSlugBox?.Text)
        && !string.IsNullOrWhiteSpace(OwnerNameBox?.Text)
        && !string.IsNullOrWhiteSpace(OwnerEmailBox?.Text)
        && OwnerEmailBox?.Text.Contains('@') == true;

    public CreateGroupRequest BuildRequest()
    {
        string? password = string.IsNullOrWhiteSpace(OwnerPasswordBox?.Password) ? null : OwnerPasswordBox.Password;
        return new CreateGroupRequest(
            Name: GroupNameBox.Text.Trim(),
            Slug: GroupSlugBox.Text.Trim().ToLowerInvariant(),
            GroupOwner: new GroupOwnerPayload(
                Name: OwnerNameBox.Text.Trim(),
                Email: OwnerEmailBox.Text.Trim().ToLowerInvariant(),
                Password: password));
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
