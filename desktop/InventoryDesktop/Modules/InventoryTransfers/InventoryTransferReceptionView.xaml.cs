using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryTransfers;

public partial class InventoryTransferReceptionView : UserControl
{
    private InventoryTransferReceptionViewModel? viewModel;

    public InventoryTransferReceptionView()
    {
        InitializeComponent();
    }

    public void Configure(ApiClient apiClient)
    {
        viewModel = new InventoryTransferReceptionViewModel(apiClient);
        DataContext = viewModel;
    }

    public async Task LoadAsync()
    {
        if (viewModel is not null)
        {
            await viewModel.LoadAsync();
        }
    }

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        await LoadAsync();
    }

    private void ReceiveComplete_Click(object sender, RoutedEventArgs e)
    {
        viewModel?.ReceiveComplete();
    }

    private async void ConfirmReception_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel is null)
        {
            return;
        }

        bool completed = await viewModel.ConfirmReceptionAsync();
        if (completed)
        {
            MessageBox.Show(
                Window.GetWindow(this),
                "Recepción confirmada correctamente.",
                "Traslado recibido",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
        }
    }
}
