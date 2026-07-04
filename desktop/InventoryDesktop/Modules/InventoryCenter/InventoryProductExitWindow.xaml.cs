using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Globalization;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Media;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductExitWindow : Window, INotifyPropertyChanged
{
    private readonly InventoryProductDetailData detail;
    private readonly ApiClient apiClient;
    private InventoryWarehouseOption? selectedWarehouse;
    private ProductExitReasonOption selectedReason = ProductExitReasonOption.Default;
    private string statusMessage = "Completa los datos de la salida.";
    private bool isStatusError;
    private bool isBusy;

    public InventoryProductExitWindow(InventoryProductDetailData detail, ApiClient apiClient)
    {
        this.detail = detail;
        this.apiClient = apiClient;

        ProductName = detail.Product.Name;
        ProductSku = detail.Product.Sku;
        foreach (InventoryWarehouseStock warehouse in detail.Stock.ByWarehouse)
        {
            Warehouses.Add(InventoryWarehouseOption.FromStock(warehouse));
        }

        foreach (InventoryProductSerial serial in detail.Serials.Items.Where(serial => serial.Status == "available"))
        {
            AvailableSerials.Add(serial);
        }

        SelectedWarehouse = Warehouses.FirstOrDefault();

        InitializeComponent();
        DataContext = this;
        SerialsPanel.Visibility = detail.Product.TrackingType == "serialized" ? Visibility.Visible : Visibility.Collapsed;
        Loaded += async (_, _) => await LoadWarehousesAsync();
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public string ProductName { get; }

    public string ProductSku { get; }

    public ObservableCollection<InventoryWarehouseOption> Warehouses { get; } = new();

    public ObservableCollection<InventoryProductSerial> AvailableSerials { get; } = new();

    public IReadOnlyList<ProductExitReasonOption> Reasons { get; } =
    [
        ProductExitReasonOption.Default,
        new("damaged", "Dañado"),
        new("lost", "Extraviado"),
        new("warranty", "Garantía"),
        new("administrative", "Administrativo"),
        new("other", "Otro"),
    ];

    public InventoryWarehouseOption? SelectedWarehouse
    {
        get => selectedWarehouse;
        set => SetProperty(ref selectedWarehouse, value);
    }

    public ProductExitReasonOption SelectedReason
    {
        get => selectedReason;
        set => SetProperty(ref selectedReason, value);
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public bool IsStatusError
    {
        get => isStatusError;
        set
        {
            if (SetProperty(ref isStatusError, value))
            {
                RaisePropertyChanged(nameof(StatusBrush));
            }
        }
    }

    private async void Save_Click(object sender, RoutedEventArgs e)
    {
        if (isBusy)
        {
            return;
        }

        if (!TryBuildRequest(out ProductExitStoreRequest? request))
        {
            return;
        }

        isBusy = true;
        IsStatusError = false;
        StatusMessage = "Guardando salida...";

        try
        {
            ProductMovementCreatedResponse response = await apiClient.PostAsync<ProductExitStoreRequest, ProductMovementCreatedResponse>(
                "product-exits",
                request!);

            StatusMessage = $"Salida registrada: {response.Data.DocumentNumber ?? "sin numero"}.";
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API para guardar la salida.";
            IsStatusError = true;
        }
        catch (TaskCanceledException)
        {
            StatusMessage = "La salida tardó demasiado en responder. Intenta nuevamente.";
            IsStatusError = true;
        }
        finally
        {
            isBusy = false;
        }
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private bool TryBuildRequest(out ProductExitStoreRequest? request)
    {
        request = null;

        if (SelectedWarehouse is null)
        {
            return Fail("Selecciona un almacén.");
        }

        if (!TryReadDecimal(QuantityBox.Text, out decimal quantity) || quantity <= 0)
        {
            return Fail("La cantidad debe ser mayor a cero.");
        }

        IReadOnlyList<long>? productUnitIds = null;
        if (detail.Product.TrackingType == "serialized")
        {
            if (quantity != decimal.Truncate(quantity))
            {
                return Fail("La cantidad de un producto serializado debe ser un número entero.");
            }

            List<long> selectedIds = SerialsList.SelectedItems
                .OfType<InventoryProductSerial>()
                .Select(serial => serial.Id)
                .ToList();

            if (selectedIds.Count != (int) quantity)
            {
                return Fail("La cantidad debe coincidir con los IMEI/seriales seleccionados.");
            }

            productUnitIds = selectedIds;
        }

        request = new ProductExitStoreRequest(
            SelectedReason.Value,
            EmptyToNull(ReferenceBox.Text),
            EmptyToNull(NotesBox.Text),
            new[]
            {
                new ProductExitStoreItemRequest(
                    SelectedWarehouse.Id,
                    detail.Product.Id,
                    quantity,
                    productUnitIds),
            });

        return true;
    }

    private async Task LoadWarehousesAsync()
    {
        try
        {
            WarehouseListResponse response = await apiClient.GetAsync<WarehouseListResponse>("warehouses");
            List<InventoryWarehouseOption> activeWarehouses = response.Data
                .Where(warehouse => warehouse.Status is null || warehouse.Status == "active")
                .ToList();

            if (activeWarehouses.Count == 0)
            {
                return;
            }

            long? selectedId = SelectedWarehouse?.Id;
            Warehouses.Clear();
            foreach (InventoryWarehouseOption warehouse in activeWarehouses)
            {
                Warehouses.Add(warehouse);
            }

            SelectedWarehouse = Warehouses.FirstOrDefault(warehouse => warehouse.Id == selectedId) ?? Warehouses.FirstOrDefault();
        }
        catch
        {
            if (Warehouses.Count == 0)
            {
                StatusMessage = "No se pudieron cargar los almacenes.";
                IsStatusError = true;
            }
        }
    }

    private bool Fail(string message)
    {
        StatusMessage = message;
        IsStatusError = true;
        return false;
    }

    private static bool TryReadDecimal(string value, out decimal result)
    {
        return decimal.TryParse(value, NumberStyles.Number, CultureInfo.CurrentCulture, out result)
            || decimal.TryParse(value, NumberStyles.Number, CultureInfo.InvariantCulture, out result);
    }

    private static string? EmptyToNull(string value)
    {
        return string.IsNullOrWhiteSpace(value) ? null : value.Trim();
    }

    private bool SetProperty<T>(ref T field, T value, [CallerMemberName] string? propertyName = null)
    {
        if (EqualityComparer<T>.Default.Equals(field, value))
        {
            return false;
        }

        field = value;
        RaisePropertyChanged(propertyName);
        return true;
    }

    private void RaisePropertyChanged([CallerMemberName] string? propertyName = null)
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
    }
}

public sealed record ProductExitReasonOption(string Value, string Label)
{
    public static ProductExitReasonOption Default { get; } = new("internal_use", "Uso interno");
}
