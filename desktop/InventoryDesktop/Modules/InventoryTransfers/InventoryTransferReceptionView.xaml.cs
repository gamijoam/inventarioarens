using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryTransfers;

public partial class InventoryTransferReceptionView : UserControl
{
    private ApiClient? apiClient;
    private InventoryTransferReceptionViewModel? viewModel;

    public InventoryTransferReceptionView()
    {
        InitializeComponent();
    }

    public void Configure(ApiClient apiClient)
    {
        this.apiClient = apiClient;
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

    private async void CreateTransfer_Click(object sender, RoutedEventArgs e)
    {
        if (apiClient is null)
        {
            return;
        }

        Window? owner = Window.GetWindow(this);
        InventoryTransferCreationWindow dialog = new(
            apiClient,
            onTransferCreated: OnTransferCreated)
        {
            Owner = owner,
        };

        dialog.ShowDialog();
    }

    private void OnTransferCreated(long transferId)
    {
        _ = LoadAsync();
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

    private async void ImeiPicker_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel is null || apiClient is null)
        {
            return;
        }

        if (sender is not FrameworkElement element || element.Tag is not InventoryTransferOperationLine line)
        {
            return;
        }

        InventoryTransferItem? transfer = viewModel.SelectedTransfer;
        if (transfer is null)
        {
            return;
        }

        long? warehouseId = transfer.FromWarehouse?.Id;
        if (warehouseId is null || warehouseId <= 0)
        {
            MessageBox.Show(
                Window.GetWindow(this),
                "La guia no tiene almacen de origen definido.",
                "IMEI picker",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
            return;
        }

        // Para prepare los IMEIs disponibles del pool original son ProductUnitIds (status=available).
        // Para receive los IMEIs preparados estan RESERVED en el origen (siguen en el origen hasta
        // que el receive los mueva al destino). En ambos casos consultamos al origen.
        string actionLabel = viewModel.SelectedStage switch
        {
            InventoryTransferStage.Preparation => "preparar",
            InventoryTransferStage.Reception => "recibir",
            _ => "seleccionar",
        };

        string statusFilter = viewModel.SelectedStage == InventoryTransferStage.Preparation
            ? "all"
            : "reserved";

        IReadOnlyList<long> allowedIds = viewModel.SelectedStage == InventoryTransferStage.Reception
            ? line.PreparedUnitIds
            : line.ProductUnitIds;

        IReadOnlyList<long> initialSelection = viewModel.SelectedStage == InventoryTransferStage.Reception
            ? line.ReceivedUnitIds
            : line.PreparedUnitIds;

        int required = (int)Math.Truncate(Math.Max(line.WorkQuantity, 0));

        InventoryTransferImeiPickerWindow picker = new(
            apiClient,
            line.ProductId,
            warehouseId.Value,
            statusFilter,
            line.ProductName,
            actionLabel,
            required,
            allowedIds,
            initialSelection)
        {
            Owner = Window.GetWindow(this),
        };

        bool? result = picker.ShowDialog();
        if (result != true)
        {
            return;
        }

        line.SetSelectedSerialIds(picker.SelectedIds);
        viewModel?.SetExternalStatus($"IMEIs actualizados para {line.ProductName}: {line.SelectedSerialsCount} de {required} seleccionados.", false);
    }
}
