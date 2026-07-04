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
            await OpenDetailWindowAsync(ViewModel.SelectedProduct);
        }
    }

    private async void ViewProduct_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || sender is not FrameworkElement { DataContext: InventoryProductItem product })
        {
            return;
        }

        await OpenDetailWindowAsync(product);
    }

    private async void CreateProduct_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        InventoryProductFormWindow window = new(ViewModel.ApiClient)
        {
            Owner = Window.GetWindow(this)
        };
        window.Closed += async (_, _) =>
        {
            if (window.WasSaved)
            {
                await ViewModel.LoadAsync();
            }
        };
        window.Show();
        await window.InitializeAsync();
        window.Activate();
    }

    private void OpenAlerts_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || ViewModel.Alerts.Count == 0)
        {
            return;
        }

        InventoryAlertsWindow window = new(ViewModel.Alerts.ToList())
        {
            Owner = Window.GetWindow(this)
        };
        window.Show();
        window.Activate();
    }

    private async void EditProduct_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || sender is not FrameworkElement { DataContext: InventoryProductItem product })
        {
            return;
        }

        InventoryProductFormWindow window = new(ViewModel.ApiClient, product.Id)
        {
            Owner = Window.GetWindow(this)
        };
        window.Closed += async (_, _) =>
        {
            if (window.WasSaved)
            {
                await ViewModel.LoadAsync();
            }
        };
        window.Show();
        await window.InitializeAsync();
        window.Activate();
    }

    private void CloseDetail_Click(object sender, RoutedEventArgs e)
    {
        ViewModel?.CloseProductDetail();
    }

    private async Task OpenDetailWindowAsync(InventoryProductItem product)
    {
        if (ViewModel is null)
        {
            return;
        }

        InventoryProductDetailData? detail = await ViewModel.LoadProductDetailAsync(product);
        if (detail is null)
        {
            return;
        }

        InventoryProductDetailWindow window = new(detail, ViewModel.ApiClient)
        {
            Owner = Window.GetWindow(this)
        };
        window.ProductChanged += async (_, _) => await ViewModel.LoadAsync();
        window.Show();
        window.Activate();
    }
}
