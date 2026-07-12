using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Globalization;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Media;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductEntryWindow : Window, INotifyPropertyChanged
{
    private readonly InventoryProductDetailData detail;
    private readonly ApiClient apiClient;
    private InventoryWarehouseOption? selectedWarehouse;
    private string statusMessage = "Completa los datos de la entrada.";
    private string serialSummary = "Sin IMEI/seriales detectados.";
    private bool isSerialSummaryError;
    private bool isStatusError;
    private bool isBusy;
    private bool isUpdatingSerialText;

    public InventoryProductEntryWindow(InventoryProductDetailData detail, ApiClient apiClient)
    {
        this.detail = detail;
        this.apiClient = apiClient;

        ProductName = detail.Product.Name;
        ProductSku = detail.Product.Sku;
        foreach (InventoryWarehouseStock warehouse in detail.Stock.ByWarehouse)
        {
            Warehouses.Add(InventoryWarehouseOption.FromStock(warehouse));
        }

        SelectedWarehouse = Warehouses.FirstOrDefault();

        InitializeComponent();
        DataContext = this;
        SerialsPanel.Visibility = detail.Product.TrackingType == "serialized" ? Visibility.Visible : Visibility.Collapsed;
        Loaded += async (_, _) =>
        {
            RefreshSerialPreview();
            await LoadWarehousesAsync();
        };
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public event EventHandler? Saved;

    public bool WasSaved { get; private set; }

    public string ProductName { get; }

    public string ProductSku { get; }

    public ObservableCollection<InventoryWarehouseOption> Warehouses { get; } = new();

    public ObservableCollection<SerialPreviewItem> SerialPreview { get; } = new();

    public InventoryWarehouseOption? SelectedWarehouse
    {
        get => selectedWarehouse;
        set => SetProperty(ref selectedWarehouse, value);
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public string SerialSummary
    {
        get => serialSummary;
        set => SetProperty(ref serialSummary, value);
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public Brush SerialSummaryBrush => isSerialSummaryError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(4, 120, 87));

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

        if (!TryBuildRequest(out ProductEntryStoreRequest? request))
        {
            return;
        }

        isBusy = true;
        IsStatusError = false;
        StatusMessage = "Guardando entrada...";

        try
        {
            ProductMovementCreatedResponse response = await apiClient.PostAsync<ProductEntryStoreRequest, ProductMovementCreatedResponse>(
                "product-entries",
                request!);

            WasSaved = true;
            StatusMessage = $"Entrada registrada: {response.Data.DocumentNumber ?? "sin nÃºmero"}.";
            Saved?.Invoke(this, EventArgs.Empty);
            Close();
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API para guardar la entrada.";
            IsStatusError = true;
        }
        catch (TaskCanceledException)
        {
            StatusMessage = "La entrada tardÃ³ demasiado en responder. Intenta nuevamente.";
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

    private void BackToModules_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private void SerialsBox_TextChanged(object sender, System.Windows.Controls.TextChangedEventArgs e)
    {
        if (!isUpdatingSerialText)
        {
            RefreshSerialPreview();
        }
    }

    private void QuantityBox_TextChanged(object sender, System.Windows.Controls.TextChangedEventArgs e)
    {
        if (detail.Product.TrackingType == "serialized")
        {
            RefreshSerialPreview();
        }
    }

    private void UseSerialCount_Click(object sender, RoutedEventArgs e)
    {
        int validCount = SerialPreview.Count(item => item.IsUsable);
        if (validCount > 0)
        {
            QuantityBox.Text = validCount.ToString(CultureInfo.CurrentCulture);
        }
    }

    private void RemoveDuplicateSerials_Click(object sender, RoutedEventArgs e)
    {
        List<string> uniqueSerials = ReadSerialLines(includeEmpty: false)
            .Distinct(StringComparer.OrdinalIgnoreCase)
            .ToList();

        isUpdatingSerialText = true;
        SerialsBox.Text = string.Join(Environment.NewLine, uniqueSerials);
        isUpdatingSerialText = false;
        RefreshSerialPreview();
    }

    private bool TryBuildRequest(out ProductEntryStoreRequest? request)
    {
        request = null;

        if (SelectedWarehouse is null)
        {
            return Fail("Selecciona un almacÃ©n.");
        }

        if (!TryReadDecimal(QuantityBox.Text, out decimal quantity) || quantity <= 0)
        {
            return Fail("La cantidad debe ser mayor a cero.");
        }

        decimal? unitCost = null;
        if (!string.IsNullOrWhiteSpace(UnitCostBox.Text))
        {
            if (!TryReadDecimal(UnitCostBox.Text, out decimal parsedCost) || parsedCost < 0)
            {
                return Fail("El costo unitario no es vÃ¡lido.");
            }

            unitCost = parsedCost;
        }

        string reason = ReasonBox.Text.Trim();
        if (string.IsNullOrWhiteSpace(reason))
        {
            return Fail("El motivo es obligatorio.");
        }

        IReadOnlyList<ProductEntrySerialUnitRequest>? serialUnits = null;
        if (detail.Product.TrackingType == "serialized")
        {
            if (!ValidateSerialsForSave(quantity, out List<string> serials))
            {
                return false;
            }

            serialUnits = serials
                .Select(serial => new ProductEntrySerialUnitRequest("imei", serial))
                .ToList();
        }

        request = new ProductEntryStoreRequest(
            reason,
            EmptyToNull(ReferenceBox.Text),
            EmptyToNull(NotesBox.Text),
            new[]
            {
                new ProductEntryStoreItemRequest(
                    SelectedWarehouse.Id,
                    detail.Product.Id,
                    quantity,
                    unitCost,
                    serialUnits),
            });

        return true;
    }

    private bool ValidateSerialsForSave(decimal quantity, out List<string> serials)
    {
        serials = new();

        if (quantity != decimal.Truncate(quantity))
        {
            return Fail("La cantidad de un producto serializado debe ser un nÃºmero entero.");
        }

        List<string> rawLines = ReadSerialLines(includeEmpty: false);
        List<string> duplicateValues = rawLines
            .GroupBy(line => line, StringComparer.OrdinalIgnoreCase)
            .Where(group => group.Count() > 1)
            .Select(group => group.Key)
            .ToList();

        if (duplicateValues.Count > 0)
        {
            return Fail($"Hay IMEI/seriales duplicados: {string.Join(", ", duplicateValues.Take(3))}.");
        }

        if (rawLines.Count != (int) quantity)
        {
            return Fail("La cantidad debe coincidir con los IMEI/seriales escritos.");
        }

        if (rawLines.Any(serial => serial.Length < 6))
        {
            return Fail("Hay IMEI/seriales demasiado cortos. Revisa la vista previa.");
        }

        serials = rawLines;
        return true;
    }

    private void RefreshSerialPreview()
    {
        if (detail.Product.TrackingType != "serialized" || !IsInitialized)
        {
            return;
        }

        List<string> lines = ReadSerialLines(includeEmpty: true);
        Dictionary<string, int> counts = lines
            .Where(line => !string.IsNullOrWhiteSpace(line))
            .GroupBy(line => line.Trim(), StringComparer.OrdinalIgnoreCase)
            .ToDictionary(group => group.Key, group => group.Count(), StringComparer.OrdinalIgnoreCase);

        SerialPreview.Clear();
        int lineNumber = 1;
        foreach (string rawLine in lines)
        {
            string serial = rawLine.Trim();
            if (string.IsNullOrWhiteSpace(serial))
            {
                SerialPreview.Add(new SerialPreviewItem(lineNumber, "(lÃ­nea vacÃ­a)", "VacÃ­a", false));
            }
            else if (counts.TryGetValue(serial, out int count) && count > 1)
            {
                SerialPreview.Add(new SerialPreviewItem(lineNumber, serial, "Duplicado", false));
            }
            else if (serial.Length < 6)
            {
                SerialPreview.Add(new SerialPreviewItem(lineNumber, serial, "Muy corto", false));
            }
            else
            {
                SerialPreview.Add(new SerialPreviewItem(lineNumber, serial, "Correcto", true));
            }

            lineNumber++;
        }

        int valid = SerialPreview.Count(item => item.IsUsable);
        int errors = SerialPreview.Count(item => !item.IsUsable);
        int expected = TryReadDecimal(QuantityBox.Text, out decimal quantity) ? (int) quantity : 0;
        bool countMismatch = expected > 0 && valid != expected;
        isSerialSummaryError = errors > 0 || countMismatch;

        SerialSummary = countMismatch
            ? $"{valid} IMEI/seriales vÃ¡lidos de {expected} requeridos."
            : $"{valid} IMEI/seriales vÃ¡lidos detectados.";

        if (errors > 0)
        {
            SerialSummary += $" {errors} lÃ­nea(s) requieren atenciÃ³n.";
        }

        RaisePropertyChanged(nameof(SerialSummaryBrush));
    }

    private List<string> ReadSerialLines(bool includeEmpty)
    {
        StringSplitOptions options = includeEmpty ? StringSplitOptions.None : StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries;
        return SerialsBox.Text
            .Replace("\r\n", "\n", StringComparison.Ordinal)
            .Split('\n', options)
            .Select(line => includeEmpty ? line : line.Trim())
            .ToList();
    }

    private bool Fail(string message)
    {
        StatusMessage = message;
        IsStatusError = true;
        return false;
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

public sealed record SerialPreviewItem(int LineNumber, string SerialNumber, string StatusLabel, bool IsUsable);

