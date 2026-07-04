using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;

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

    private async void ProductsGrid_MouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        if (ViewModel?.SelectedProduct is not null)
        {
            await ViewModel.OpenProductDetailAsync(ViewModel.SelectedProduct);
        }
    }

    private async void ViewProduct_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || sender is not FrameworkElement { DataContext: InventoryProductItem product })
        {
            return;
        }

        await ViewModel.OpenProductDetailAsync(product);
    }

    private void CloseDetail_Click(object sender, RoutedEventArgs e)
    {
        ViewModel?.CloseProductDetail();
    }
}
