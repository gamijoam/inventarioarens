using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Modules.Auth;

namespace InventoryDesktop.Modules.Admin;

public partial class SwitchTenantDialog : Window, INotifyPropertyChanged
{
    private SwitchTenantItem? selectedItem;

    public event PropertyChangedEventHandler? PropertyChanged;

    public ObservableCollection<SwitchTenantItem> Items { get; } = new();

    public SwitchTenantItem? SelectedItem
    {
        get => selectedItem;
        set
        {
            if (SetField(ref selectedItem, value))
            {
                RaisePropertyChanged(nameof(HasSelection));
            }
        }
    }

    public bool HasSelection => SelectedItem is not null && SelectedItem.Slug != CurrentTenantSlug;

    public string? CurrentTenantSlug { get; }

    public string? SelectedTenantSlug => SelectedItem?.Slug;

    public SwitchTenantDialog(System.Collections.Generic.IReadOnlyList<TenantOption> tenants, string? currentTenantSlug)
    {
        InitializeComponent();
        CurrentTenantSlug = currentTenantSlug;
        DataContext = this;
        foreach (TenantOption tenant in tenants)
        {
            Items.Add(new SwitchTenantItem(
                tenant.Id,
                tenant.Name,
                tenant.Slug,
                tenant.Slug == currentTenantSlug));
        }
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private void Accept_Click(object sender, RoutedEventArgs e)
    {
        if (!HasSelection)
        {
            return;
        }
        DialogResult = true;
        Close();
    }

    private bool SetField<T>(ref T field, T value, [CallerMemberName] string? propertyName = null)
    {
        if (System.Collections.Generic.EqualityComparer<T>.Default.Equals(field, value))
        {
            return false;
        }
        field = value;
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
        return true;
    }

    private void RaisePropertyChanged([CallerMemberName] string? propertyName = null)
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
    }
}

public sealed class SwitchTenantItem
{
    public long Id { get; }
    public string Name { get; }
    public string Slug { get; }
    public bool IsCurrent { get; }

    public SwitchTenantItem(long id, string name, string slug, bool isCurrent)
    {
        Id = id;
        Name = name;
        Slug = slug;
        IsCurrent = isCurrent;
    }
}
