using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Controls;

namespace InventoryDesktop.Modules.Admin;

public partial class EditGroupDialog : Window, INotifyPropertyChanged
{
    public event PropertyChangedEventHandler? PropertyChanged;

    public EditGroupDialog(GroupResource group)
    {
        InitializeComponent();
        DataContext = this;
        NameBox.Text = group.Name;
        SlugBox.Text = group.Slug;
        DomainBox.Text = group.Domain ?? string.Empty;
        PlanBox.Text = group.Plan ?? string.Empty;
        foreach (ComboBoxItem item in StatusBox.Items)
        {
            if (item.Content?.ToString() == group.Status)
            {
                StatusBox.SelectedItem = item;
                break;
            }
        }
        if (StatusBox.SelectedItem is null && StatusBox.Items.Count > 0)
        {
            StatusBox.SelectedIndex = 0;
        }

        NameBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
        SlugBox.TextChanged += (_, _) => RaisePropertyChanged(nameof(CanAccept));
    }

    public bool CanAccept =>
        !string.IsNullOrWhiteSpace(NameBox?.Text)
        && !string.IsNullOrWhiteSpace(SlugBox?.Text)
        && StatusBox?.SelectedItem is not null;

    public UpdateGroupRequest BuildRequest()
    {
        return new UpdateGroupRequest(
            Name: NameBox.Text.Trim(),
            Slug: SlugBox.Text.Trim().ToLowerInvariant(),
            Domain: string.IsNullOrWhiteSpace(DomainBox?.Text) ? null : DomainBox.Text.Trim(),
            Plan: string.IsNullOrWhiteSpace(PlanBox?.Text) ? null : PlanBox.Text.Trim(),
            Status: (StatusBox.SelectedItem as ComboBoxItem)?.Content?.ToString() ?? "active");
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