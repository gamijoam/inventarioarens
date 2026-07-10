using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryTransfers;

public partial class TransferProductSearchWindow : Window
{
    private readonly ApiClient apiClient;
    private readonly long warehouseId;
    private string? currentSearch;
    private string? currentTrackingType;

    public TransferProductSearchWindow(ApiClient apiClient, long warehouseId)
    {
        InitializeComponent();
        this.apiClient = apiClient;
        this.warehouseId = warehouseId;
        WarehouseHint.Text = $"Productos activos del almacen #{warehouseId}.";
    }

    public TransferProductOption? SelectedProduct { get; private set; }

    public decimal SelectedQuantity { get; private set; } = 1m;

    private async void ProductSearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            e.Handled = true;
            await SearchAsync();
        }
    }

    private async void Search_Click(object sender, RoutedEventArgs e)
    {
        await SearchAsync();
    }

    private async Task SearchAsync()
    {
        string search = ProductSearchBox.Text.Trim();
        string? tracking = (TrackingTypeBox.SelectedItem as ComboBoxItem)?.Tag as string;
        currentSearch = string.IsNullOrWhiteSpace(search) ? null : search;
        currentTrackingType = string.IsNullOrWhiteSpace(tracking) ? null : tracking;

        SelectButton.IsEnabled = false;
        StatusText.Text = "Buscando productos...";
        try
        {
            List<string> query = new();
            query.Add("active_status=active");
            query.Add("limit=50");
            if (!string.IsNullOrWhiteSpace(currentSearch))
            {
                query.Add($"search={Uri.EscapeDataString(currentSearch)}");
            }
            if (!string.IsNullOrWhiteSpace(currentTrackingType))
            {
                query.Add($"tracking_type={Uri.EscapeDataString(currentTrackingType)}");
            }
            string queryString = string.Join("&", query);
            TransferProductSearchResponse response = await apiClient
                .GetAsync<TransferProductSearchResponse>($"products?{queryString}");
            IReadOnlyList<TransferProductOption> products = response.Data ?? Array.Empty<TransferProductOption>();
            ProductsList.ItemsSource = products;
            StatusText.Text = products.Count == 0
                ? "Sin resultados para esa busqueda."
                : $"{products.Count} producto(s) encontrado(s).";
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            ProductsList.ItemsSource = Array.Empty<TransferProductOption>();
            StatusText.Text = exception is ApiException
                ? exception.Message
                : "No se pudo conectar con el servidor.";
        }
    }

    private void TrackingType_Changed(object sender, SelectionChangedEventArgs e)
    {
        if (!IsLoaded)
        {
            return;
        }

        _ = SearchAsync();
    }

    private void ProductsList_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        SelectButton.IsEnabled = ProductsList.SelectedItem is TransferProductOption;
    }

    private void ProductsList_MouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        if (ProductsList.SelectedItem is TransferProductOption)
        {
            ConfirmSelection();
        }
    }

    private void Select_Click(object sender, RoutedEventArgs e)
    {
        ConfirmSelection();
    }

    private void ConfirmSelection()
    {
        if (ProductsList.SelectedItem is not TransferProductOption product)
        {
            return;
        }

        if (!decimal.TryParse(QuantityBox.Text.Replace(',', '.'), System.Globalization.NumberStyles.Any,
                System.Globalization.CultureInfo.InvariantCulture, out decimal quantity) || quantity <= 0)
        {
            MessageBox.Show(
                "Ingresa una cantidad valida mayor a cero.",
                "Cantidad",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
            QuantityBox.Focus();
            return;
        }

        SelectedProduct = product;
        SelectedQuantity = quantity;
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
