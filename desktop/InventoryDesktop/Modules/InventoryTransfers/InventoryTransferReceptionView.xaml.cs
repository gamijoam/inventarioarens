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

    private async void Preparation_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel is not null)
        {
            await viewModel.SetStageAsync(InventoryTransferStage.Preparation);
        }
    }

    private async void DispatchStage_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel is not null)
        {
            await viewModel.SetStageAsync(InventoryTransferStage.Dispatch);
        }
    }

    private async void Reception_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel is not null)
        {
            await viewModel.SetStageAsync(InventoryTransferStage.Reception);
        }
    }

    private void CompleteCurrentStage_Click(object sender, RoutedEventArgs e)
    {
        viewModel?.CompleteCurrentStage();
    }

    private async void ConfirmPreparation_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel is null)
        {
            return;
        }

        bool completed = await viewModel.ConfirmPreparationAsync();
        if (completed)
        {
            MessageBox.Show(
                Window.GetWindow(this),
                "Preparación confirmada correctamente.",
                "Traslado preparado",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
        }
    }

    private async void ConfirmDispatch_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel is null)
        {
            return;
        }

        bool completed = await viewModel.ConfirmDispatchAsync();
        if (completed)
        {
            MessageBox.Show(
                Window.GetWindow(this),
                "Despacho confirmado correctamente.",
                "Traslado despachado",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
        }
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
