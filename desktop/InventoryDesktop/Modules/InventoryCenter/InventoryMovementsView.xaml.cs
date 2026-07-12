using System.Windows;
using System.Windows.Controls;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryMovementsView : UserControl
{
    public event EventHandler? BackToModulesRequested;

    public InventoryMovementsView()
    {
        InitializeComponent();
    }

    private void BackToModules_Click(object sender, RoutedEventArgs e)
    {
        BackToModulesRequested?.Invoke(this, EventArgs.Empty);
    }

    private void SearchBox_KeyDown(object sender, System.Windows.Input.KeyEventArgs e)
    {
        if (e.Key == System.Windows.Input.Key.Enter)
        {
            ApplyFilters_Click(sender, new RoutedEventArgs());
            e.Handled = true;
        }
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

    private async void Entry_Click(object sender, RoutedEventArgs e)
    {
        if (sender is FrameworkElement { DataContext: InventoryProductItem product })
        {
            await OpenMovementWindowAsync(product, isEntry: true);
        }
    }

    private async void Exit_Click(object sender, RoutedEventArgs e)
    {
        if (sender is FrameworkElement { DataContext: InventoryProductItem product })
        {
            await OpenMovementWindowAsync(product, isEntry: false);
        }
    }

    private async Task OpenMovementWindowAsync(InventoryProductItem product, bool isEntry)
    {
        if (ViewModel is null)
        {
            return;
        }

        try
        {
            InventoryProductDetailData? detail = await ViewModel.LoadProductDetailAsync(product);
            if (detail is null)
            {
                return;
            }

            Window window = isEntry
                ? new InventoryProductEntryWindow(detail, ViewModel.ApiClient)
                : new InventoryProductExitWindow(detail, ViewModel.ApiClient);

            window.Owner = Window.GetWindow(this);
            window.Closed += async (_, _) => await ViewModel.LoadAsync();
            window.Show();
            window.Activate();
        }
        catch (Exception exception)
        {
            MessageBox.Show(
                $"No se pudo abrir la ventana de {(isEntry ? "entrada" : "salida")}.\n\n{exception.Message}",
                "Sistema de Inventario",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
        }
    }
}
