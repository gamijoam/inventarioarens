using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Controls;

namespace InventoryDesktop.Modules.Admin;

public partial class CreateSpinoffDialog : Window, INotifyPropertyChanged
{
    private readonly string groupSlug;

    public event PropertyChangedEventHandler? PropertyChanged;

    public CreateSpinoffDialog(string groupSlug)
    {
        this.groupSlug = groupSlug;
        InitializeComponent();
        DataContext = this;
        HeaderText.Text = $"Nuevo spinoff de '{groupSlug}'";
        SpinoffNameBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        SpinoffSlugBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        AdminNameBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        AdminEmailBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
    }

    public bool CanAccept =>
        !string.IsNullOrWhiteSpace(SpinoffNameBox?.Text)
        && !string.IsNullOrWhiteSpace(SpinoffSlugBox?.Text)
        && !string.IsNullOrWhiteSpace(AdminNameBox?.Text)
        && !string.IsNullOrWhiteSpace(AdminEmailBox?.Text)
        && AdminEmailBox?.Text.Contains('@') == true;

    public CreateSpinoffRequest BuildRequest()
    {
        string? password = string.IsNullOrWhiteSpace(AdminPasswordBox?.Password) ? null : AdminPasswordBox.Password;
        return new CreateSpinoffRequest(
            Name: SpinoffNameBox.Text.Trim(),
            Slug: SpinoffSlugBox.Text.Trim().ToLowerInvariant(),
            Admin: new GroupOwnerPayload(
                Name: AdminNameBox.Text.Trim(),
                Email: AdminEmailBox.Text.Trim().ToLowerInvariant(),
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
