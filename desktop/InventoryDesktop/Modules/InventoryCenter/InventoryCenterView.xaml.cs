using System.Windows;
using System.Windows.Controls;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryCenterView : UserControl
{
    public InventoryCenterView()
    {
        InitializeComponent();
    }

    private InventoryCenterViewModel? ViewModel => DataContext as InventoryCenterViewModel;

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.LoadAsync();
        }
    }

    private async void ApplyFilters_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.ApplyFiltersAsync();
        }
    }

    private async void ClearFilters_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.ClearFiltersAsync();
        }
    }

    private async void PreviousPage_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.PreviousPageAsync();
        }
    }

    private async void NextPage_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.NextPageAsync();
        }
    }
}
