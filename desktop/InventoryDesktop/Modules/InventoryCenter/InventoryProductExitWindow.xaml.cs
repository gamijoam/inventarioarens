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
    private string serialSelectionSummary = "0 seleccionados de 1 requerido.";
    private bool isSerialSelectionReady;
    private bool isUpdatingSerialSelection;
    private readonly HashSet<long> selectedSerialIds = new();

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
            FilteredSerials.Add(serial);
        }

        SelectedWarehouse = Warehouses.FirstOrDefault();

        InitializeComponent();
        DataContext = this;
        SerialsPanel.Visibility = detail.Product.TrackingType == "serialized" ? Visibility.Visible : Visibility.Collapsed;
        RefreshSerialSelectionSummary();
        Loaded += async (_, _) => await LoadWarehousesAsync();
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public string ProductName { get; }

    public string ProductSku { get; }

    public ObservableCollection<InventoryWarehouseOption> Warehouses { get; } = new();

    public ObservableCollection<InventoryProductSerial> AvailableSerials { get; } = new();

    public ObservableCollection<InventoryProductSerial> FilteredSerials { get; } = new();

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

    public string SerialSelectionSummary
    {
        get => serialSelectionSummary;
        set => SetProperty(ref serialSelectionSummary, value);
    }

    public Brush SerialSelectionBrush => isSerialSelectionReady
        ? new SolidColorBrush(Color.FromRgb(5, 133, 97))
        : new SolidColorBrush(Color.FromRgb(217, 54, 92));

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

    private void QuantityBox_TextChanged(object sender, System.Windows.Controls.TextChangedEventArgs e)
    {
        RefreshSerialSelectionSummary();
    }

    private void SerialSearchBox_TextChanged(object sender, System.Windows.Controls.TextChangedEventArgs e)
    {
        ApplySerialFilter();
    }

    private void SerialsList_SelectionChanged(object sender, System.Windows.Controls.SelectionChangedEventArgs e)
    {
        if (!isUpdatingSerialSelection)
        {
            foreach (InventoryProductSerial serial in e.AddedItems.OfType<InventoryProductSerial>())
            {
                selectedSerialIds.Add(serial.Id);
            }

            foreach (InventoryProductSerial serial in e.RemovedItems.OfType<InventoryProductSerial>())
            {
                selectedSerialIds.Remove(serial.Id);
            }

            RefreshSerialSelectionSummary();
        }
    }

    private void ClearSerialSelection_Click(object sender, RoutedEventArgs e)
    {
        selectedSerialIds.Clear();
        SerialsList.SelectedItems.Clear();
        RefreshSerialSelectionSummary();
    }

    private void UseSelectedCount_Click(object sender, RoutedEventArgs e)
    {
        QuantityBox.Text = SelectedSerials().Count.ToString(CultureInfo.CurrentCulture);
        RefreshSerialSelectionSummary();
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

            List<long> selectedIds = SelectedSerials()
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

    private void ApplySerialFilter()
    {
        string filter = SerialSearchBox?.Text?.Trim() ?? string.Empty;

        isUpdatingSerialSelection = true;
        try
        {
            FilteredSerials.Clear();
            foreach (InventoryProductSerial serial in AvailableSerials.Where(serial => MatchesSerialFilter(serial, filter)))
            {
                FilteredSerials.Add(serial);
            }

            SerialsList.SelectedItems.Clear();
            foreach (InventoryProductSerial serial in FilteredSerials.Where(serial => selectedSerialIds.Contains(serial.Id)))
            {
                SerialsList.SelectedItems.Add(serial);
            }
        }
        finally
        {
            isUpdatingSerialSelection = false;
        }

        RefreshSerialSelectionSummary();
    }

    private static bool MatchesSerialFilter(InventoryProductSerial serial, string filter)
    {
        if (string.IsNullOrWhiteSpace(filter))
        {
            return true;
        }

        return Contains(serial.SerialNumber, filter)
            || Contains(serial.WarehouseLabel, filter)
            || Contains(serial.StatusLabel, filter);
    }

    private static bool Contains(string value, string filter)
    {
        return value.Contains(filter, StringComparison.CurrentCultureIgnoreCase);
    }

    private IReadOnlyList<InventoryProductSerial> SelectedSerials()
    {
        return AvailableSerials
            .Where(serial => selectedSerialIds.Contains(serial.Id))
            .ToList();
    }

    private void RefreshSerialSelectionSummary()
    {
        if (detail.Product.TrackingType != "serialized")
        {
            return;
        }

        int selectedCount = SelectedSerials().Count;
        if (!TryReadDecimal(QuantityBox?.Text ?? string.Empty, out decimal quantity) || quantity <= 0 || quantity != decimal.Truncate(quantity))
        {
            isSerialSelectionReady = false;
            SerialSelectionSummary = $"{selectedCount} seleccionados. Ingresa una cantidad entera.";
            RaisePropertyChanged(nameof(SerialSelectionBrush));
            return;
        }

        int requiredCount = (int) quantity;
        isSerialSelectionReady = selectedCount == requiredCount;
        string requiredText = requiredCount == 1 ? "requerido" : "requeridos";
        SerialSelectionSummary = $"{selectedCount} seleccionados de {requiredCount} {requiredText}.";
        RaisePropertyChanged(nameof(SerialSelectionBrush));
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
