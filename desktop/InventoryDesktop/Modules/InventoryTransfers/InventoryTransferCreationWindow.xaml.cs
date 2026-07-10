using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.InventoryTransfers;

public partial class InventoryTransferCreationWindow : Window
{
    private readonly ApiClient apiClient;
    private readonly InventoryTransferCreationViewModel viewModel;

    public InventoryTransferCreationWindow(ApiClient apiClient, Action<long>? onTransferCreated = null)
    {
        InitializeComponent();
        this.apiClient = apiClient;
        viewModel = new InventoryTransferCreationViewModel(apiClient, onTransferCreated);
        DataContext = viewModel;
        Loaded += OnLoadedAsync;
    }

    public long? CreatedTransferId { get; private set; }

    private async void OnLoadedAsync(object sender, RoutedEventArgs e)
    {
        Loaded -= OnLoadedAsync;
        await viewModel.LoadWarehousesAsync();
    }

    private async void AddProduct_Click(object sender, RoutedEventArgs e)
    {
        if (viewModel.SelectedOrigin is null)
        {
            MessageBox.Show(
                "Selecciona primero el almacen de origen para poder elegir productos.",
                "Crear traslado",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
            return;
        }

        TransferProductSearchWindow picker = new(apiClient, viewModel.SelectedOrigin.Id)
        {
            Owner = this,
        };
        bool? result = picker.ShowDialog();
        if (result != true)
        {
            return;
        }

        if (picker.SelectedProduct is null)
        {
            return;
        }

        viewModel.AddItem(picker.SelectedProduct, picker.SelectedQuantity);
    }

    private void RemoveLine_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not FrameworkElement element || element.Tag is not TransferCreationLine line)
        {
            return;
        }

        viewModel.RemoveLine(line);
    }

    private async void Create_Click(object sender, RoutedEventArgs e)
    {
        // El boton se deshabilita por CanSubmit cuando IsBusy=true en el ViewModel.
        long? newId = await viewModel.SubmitAsync();
        if (newId is null)
        {
            return;
        }

        CreatedTransferId = newId;
        DialogResult = true;
        Close();
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }
}
