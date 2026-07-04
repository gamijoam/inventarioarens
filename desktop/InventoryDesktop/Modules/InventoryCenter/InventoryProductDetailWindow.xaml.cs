using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Globalization;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductDetailWindow : Window, INotifyPropertyChanged
{
    private readonly ApiClient apiClient;
    private InventoryProductDetailData detail;
    private InventoryPagination serialPagination = new(1, 24, 0, 1, 0, 0, false, false);
    private InventoryPagination movementPagination = new(1, 24, 0, 1, 0, 0, false, false);
    private InventoryPagination auditPagination = new(1, 24, 0, 1, 0, 0, false, false);
    private string serialStatusMessage = "Abre esta pestaña para cargar seriales/IMEI.";
    private string movementStatusMessage = "Abre esta pestaña para cargar movimientos.";
    private string auditStatusMessage = "Abre esta pestaña para cargar auditoría.";
    private string priceStatusMessage = "Abre esta pestaña para cargar precios por lista.";
    private bool isSerialStatusError;
    private bool isMovementStatusError;
    private bool isAuditStatusError;
    private bool isPriceStatusError;
    private bool serialsLoaded;
    private bool movementsLoaded;
    private bool auditsLoaded;
    private bool pricesLoaded;
    private FilterOption selectedSerialStatus = new("all", "Todos");
    private FilterOption selectedMovementType = new("all", "Todos");
    private FilterOption selectedAuditAction = new("all", "Todos");
    private WarehouseFilterOption selectedSerialWarehouse = WarehouseFilterOption.All;
    private WarehouseFilterOption selectedMovementWarehouse = WarehouseFilterOption.All;

    public event PropertyChangedEventHandler? PropertyChanged;

    public event EventHandler? ProductChanged;

    public InventoryProductDetailWindow(InventoryProductDetailData detail, ApiClient apiClient)
    {
        this.detail = detail;
        this.apiClient = apiClient;

        foreach (FilterOption option in SerialStatusOptions)
        {
            if (option.Value == "all")
            {
                selectedSerialStatus = option;
                break;
            }
        }

        foreach (FilterOption option in MovementTypeOptions)
        {
            if (option.Value == "all")
            {
                selectedMovementType = option;
                break;
            }
        }

        foreach (FilterOption option in AuditActionOptions)
        {
            if (option.Value == "all")
            {
                selectedAuditAction = option;
                break;
            }
        }

        WarehouseFilterOptions.Add(WarehouseFilterOption.All);
        foreach (InventoryWarehouseStock warehouse in detail.Stock.ByWarehouse)
        {
            WarehouseFilterOptions.Add(new WarehouseFilterOption(warehouse.WarehouseId, warehouse.WarehouseLabel));
        }

        InitializeComponent();
        DataContext = this;
        Title = $"Detalle - {detail.Product.Name}";
    }

    public InventoryProductDetailData Detail
    {
        get => detail;
        private set
        {
            if (SetProperty(ref detail, value))
            {
                Title = $"Detalle - {detail.Product.Name}";
            }
        }
    }

    public ObservableCollection<InventoryProductSerial> SerialRows { get; } = new();

    public ObservableCollection<InventoryProductMovement> MovementRows { get; } = new();

    public ObservableCollection<InventoryProductAudit> AuditRows { get; } = new();

    public ObservableCollection<ProductPriceEditRow> ProductPriceRows { get; } = new();

    public ObservableCollection<WarehouseFilterOption> WarehouseFilterOptions { get; } = new();

    public ObservableCollection<ExchangeRateTypeOption> PriceRateTypes { get; } = new();

    public IReadOnlyList<ProductOption> PriceCurrencyOptions { get; } =
    [
        ProductOption.UsdCurrency,
        ProductOption.VesCurrency,
    ];

    public IReadOnlyList<FilterOption> SerialStatusOptions { get; } =
    [
        new("all", "Todos"),
        new("available", "Disponible"),
        new("reserved", "Reservado"),
        new("sold", "Vendido"),
        new("damaged", "Dañado"),
        new("removed", "Removido"),
        new("warranty_hold", "Garantía"),
    ];

    public IReadOnlyList<FilterOption> MovementTypeOptions { get; } =
    [
        new("all", "Todos"),
        new("purchase", "Entrada"),
        new("purchase_return", "Dev. proveedor"),
        new("sale", "Venta"),
        new("sale_return", "Dev. venta"),
        new("adjustment_in", "Ajuste entrada"),
        new("adjustment_out", "Ajuste salida"),
        new("transfer_in", "Traslado entrada"),
        new("transfer_out", "Traslado salida"),
        new("damaged", "Dañado"),
        new("reserved", "Reserva"),
        new("released", "Liberación"),
    ];

    public IReadOnlyList<FilterOption> AuditActionOptions { get; } =
    [
        new("all", "Todas"),
        new("created", "Creado"),
        new("updated", "Actualizado"),
        new("deactivated", "Desactivado"),
    ];

    public FilterOption SelectedSerialStatus
    {
        get => selectedSerialStatus;
        set => SetProperty(ref selectedSerialStatus, value);
    }

    public FilterOption SelectedMovementType
    {
        get => selectedMovementType;
        set => SetProperty(ref selectedMovementType, value);
    }

    public FilterOption SelectedAuditAction
    {
        get => selectedAuditAction;
        set => SetProperty(ref selectedAuditAction, value);
    }

    public WarehouseFilterOption SelectedSerialWarehouse
    {
        get => selectedSerialWarehouse;
        set => SetProperty(ref selectedSerialWarehouse, value);
    }

    public WarehouseFilterOption SelectedMovementWarehouse
    {
        get => selectedMovementWarehouse;
        set => SetProperty(ref selectedMovementWarehouse, value);
    }

    public string SerialStatusMessage
    {
        get => serialStatusMessage;
        set => SetProperty(ref serialStatusMessage, value);
    }

    public string MovementStatusMessage
    {
        get => movementStatusMessage;
        set => SetProperty(ref movementStatusMessage, value);
    }

    public string AuditStatusMessage
    {
        get => auditStatusMessage;
        set => SetProperty(ref auditStatusMessage, value);
    }

    public string PriceStatusMessage
    {
        get => priceStatusMessage;
        set => SetProperty(ref priceStatusMessage, value);
    }

    public Brush SerialStatusBrush => isSerialStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public Brush MovementStatusBrush => isMovementStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public Brush AuditStatusBrush => isAuditStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public Brush PriceStatusBrush => isPriceStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public bool CanGoPreviousSerials => serialPagination.HasPrevious;

    public bool CanGoNextSerials => serialPagination.HasNext;

    public bool CanGoPreviousMovements => movementPagination.HasPrevious;

    public bool CanGoNextMovements => movementPagination.HasNext;

    public bool CanGoPreviousAudits => auditPagination.HasPrevious;

    public bool CanGoNextAudits => auditPagination.HasNext;

    private async void DetailTabs_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (e.Source is not TabControl tabControl || tabControl.SelectedItem is not TabItem tabItem)
        {
            return;
        }

        string header = tabItem.Header?.ToString() ?? string.Empty;
        if (header == "Seriales / IMEI" && !serialsLoaded)
        {
            await LoadSerialsAsync(1);
        }
        else if (header == "Movimientos" && !movementsLoaded)
        {
            await LoadMovementsAsync(1);
        }
        else if (header == "Precios" && !pricesLoaded)
        {
            await LoadPricesAsync();
        }
        else if (header == "Auditoría" && !auditsLoaded)
        {
            await LoadAuditsAsync(1);
        }
    }

    private async void SearchSerials_Click(object sender, RoutedEventArgs e)
    {
        await LoadSerialsAsync(1);
    }

    private async void ClearSerials_Click(object sender, RoutedEventArgs e)
    {
        SerialSearchBox.Text = "";
        SelectedSerialStatus = SerialStatusOptions.First(option => option.Value == "all");
        SelectedSerialWarehouse = WarehouseFilterOption.All;
        await LoadSerialsAsync(1);
    }

    private async void PreviousSerials_Click(object sender, RoutedEventArgs e)
    {
        if (serialPagination.HasPrevious)
        {
            await LoadSerialsAsync(serialPagination.Page - 1);
        }
    }

    private async void NextSerials_Click(object sender, RoutedEventArgs e)
    {
        if (serialPagination.HasNext)
        {
            await LoadSerialsAsync(serialPagination.Page + 1);
        }
    }

    private async void SearchMovements_Click(object sender, RoutedEventArgs e)
    {
        await LoadMovementsAsync(1);
    }

    private async void ClearMovements_Click(object sender, RoutedEventArgs e)
    {
        MovementSearchBox.Text = "";
        MovementDateFromBox.Text = "";
        MovementDateToBox.Text = "";
        SelectedMovementType = MovementTypeOptions.First(option => option.Value == "all");
        SelectedMovementWarehouse = WarehouseFilterOption.All;
        await LoadMovementsAsync(1);
    }

    private async void PreviousMovements_Click(object sender, RoutedEventArgs e)
    {
        if (movementPagination.HasPrevious)
        {
            await LoadMovementsAsync(movementPagination.Page - 1);
        }
    }

    private async void NextMovements_Click(object sender, RoutedEventArgs e)
    {
        if (movementPagination.HasNext)
        {
            await LoadMovementsAsync(movementPagination.Page + 1);
        }
    }

    private async void SearchAudits_Click(object sender, RoutedEventArgs e)
    {
        await LoadAuditsAsync(1);
    }

    private async void ReloadPrices_Click(object sender, RoutedEventArgs e)
    {
        await LoadPricesAsync();
    }

    private async void SavePrices_Click(object sender, RoutedEventArgs e)
    {
        await SavePricesAsync();
    }

    private void OpenPriceHistory_Click(object sender, RoutedEventArgs e)
    {
        ProductPriceHistoryWindow window = new(detail.Product.Id, detail.Product.Name, apiClient)
        {
            Owner = this
        };
        window.Show();
    }

    private void CopyBasePrices_Click(object sender, RoutedEventArgs e)
    {
        if (detail.Product.BasePrice is null)
        {
            SetPriceError("Este producto no tiene precio base para copiar.");
            return;
        }

        int copied = 0;
        foreach (ProductPriceEditRow row in ProductPriceRows)
        {
            if (!row.HasTypedPrice)
            {
                row.CopyBasePrice();
                copied++;
            }
        }

        isPriceStatusError = false;
        RaisePropertyChanged(nameof(PriceStatusBrush));
        PriceStatusMessage = copied == 0
            ? "No había listas vacías para completar con el precio base."
            : $"Se copió el precio base en {copied} listas vacías. Presiona Guardar precios para confirmar.";
    }

    private void CopyBasePrice_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not FrameworkElement element || element.DataContext is not ProductPriceEditRow row)
        {
            return;
        }

        if (detail.Product.BasePrice is null)
        {
            SetPriceError("Este producto no tiene precio base para copiar.");
            return;
        }

        row.CopyBasePrice();
        isPriceStatusError = false;
        RaisePropertyChanged(nameof(PriceStatusBrush));
        PriceStatusMessage = $"Se copió el precio base en {row.PriceListName}. Presiona Guardar precios para confirmar.";
    }

    private async void ClearAudits_Click(object sender, RoutedEventArgs e)
    {
        AuditSearchBox.Text = "";
        SelectedAuditAction = AuditActionOptions.First(option => option.Value == "all");
        await LoadAuditsAsync(1);
    }

    private async void PreviousAudits_Click(object sender, RoutedEventArgs e)
    {
        if (auditPagination.HasPrevious)
        {
            await LoadAuditsAsync(auditPagination.Page - 1);
        }
    }

    private async void NextAudits_Click(object sender, RoutedEventArgs e)
    {
        if (auditPagination.HasNext)
        {
            await LoadAuditsAsync(auditPagination.Page + 1);
        }
    }

    private void OpenKardex_Click(object sender, RoutedEventArgs e)
    {
        TryOpenWindow(() => new InventoryProductKardexWindow(detail, apiClient), "Kardex");
    }

    private void OpenEntry_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            InventoryProductEntryWindow window = new(detail, apiClient)
            {
                Owner = this
            };
            window.Saved += MovementWindow_Saved;
            window.Show();
            window.Activate();
        }
        catch (Exception exception)
        {
            ShowOpenError("entrada", exception);
        }
    }

    private void OpenExit_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            InventoryProductExitWindow window = new(detail, apiClient)
            {
                Owner = this
            };
            window.Saved += MovementWindow_Saved;
            window.Show();
            window.Activate();
        }
        catch (Exception exception)
        {
            ShowOpenError("salida", exception);
        }
    }

    private async void EditProduct_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            InventoryProductFormWindow window = new(apiClient, detail.Product.Id)
            {
                Owner = this
            };

            window.Closed += async (_, _) =>
            {
                if (!window.WasSaved)
                {
                    return;
                }

                await RefreshAfterProductEditAsync();
            };

            window.Show();
            await window.InitializeAsync();
            window.Activate();
        }
        catch (Exception exception)
        {
            ShowOpenError("edición de producto", exception);
        }
    }

    private void TryOpenWindow(Func<Window> createWindow, string actionName)
    {
        try
        {
            Window window = createWindow();
            window.Owner = this;
            window.Show();
            window.Activate();
        }
        catch (Exception exception)
        {
            ShowOpenError(actionName, exception);
        }
    }

    private async void MovementWindow_Saved(object? sender, EventArgs e)
    {
        await RefreshDetailAsync();
        serialsLoaded = false;
        movementsLoaded = false;
        auditsLoaded = false;
        pricesLoaded = false;
        SerialRows.Clear();
        MovementRows.Clear();
        AuditRows.Clear();
        ProductPriceRows.Clear();
        SerialStatusMessage = "Seriales pendientes por recargar.";
        MovementStatusMessage = "Movimientos pendientes por recargar.";
        AuditStatusMessage = "Auditoría pendiente por recargar.";
        PriceStatusMessage = "Precios pendientes por recargar.";
        ProductChanged?.Invoke(this, EventArgs.Empty);
    }

    private async Task RefreshAfterProductEditAsync()
    {
        await RefreshDetailAsync();
        auditsLoaded = false;
        pricesLoaded = false;
        AuditRows.Clear();
        ProductPriceRows.Clear();
        AuditStatusMessage = "Auditoría pendiente por recargar.";
        PriceStatusMessage = "Precios pendientes por recargar.";
        ProductChanged?.Invoke(this, EventArgs.Empty);
    }

    private async Task RefreshDetailAsync()
    {
        try
        {
            InventoryProductDetailResponse response = await apiClient.GetAsync<InventoryProductDetailResponse>(
                $"inventory-center/products/{detail.Product.Id}");

            Detail = response.Data;
            RefreshWarehouseFilters();
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            MessageBox.Show(
                $"El movimiento se guardó, pero no se pudo actualizar el detalle automáticamente.\n\n{exception.Message}",
                "Sistema de Inventario",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }
    }

    private async Task LoadSerialsAsync(int page)
    {
        isSerialStatusError = false;
        RaisePropertyChanged(nameof(SerialStatusBrush));
        SerialStatusMessage = "Cargando seriales/IMEI...";

        try
        {
            string query = BuildQuery([
                ("search", SerialSearchBox.Text),
                ("status", SelectedSerialStatus.Value),
                ("warehouse_id", SelectedSerialWarehouse.Id?.ToString()),
                ("limit", "24"),
                ("page", page.ToString()),
            ]);

            InventoryProductSerialsPageResponse response = await apiClient.GetAsync<InventoryProductSerialsPageResponse>(
                $"inventory-center/products/{detail.Product.Id}/serials{query}");

            SerialRows.Clear();
            foreach (InventoryProductSerial serial in response.Data.Data)
            {
                SerialRows.Add(serial);
            }

            serialPagination = response.Data.Pagination;
            serialsLoaded = true;
            SerialStatusMessage = PaginationMessage(serialPagination, "seriales/IMEI");
            RaiseSerialPaginationChanged();
        }
        catch (ApiException exception)
        {
            SetSerialError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetSerialError("No se pudo conectar con la API para cargar seriales/IMEI.");
        }
        catch (TaskCanceledException)
        {
            SetSerialError("La carga de seriales/IMEI tardó demasiado. Intenta nuevamente.");
        }
    }

    private async Task LoadMovementsAsync(int page)
    {
        if (!ValidateMovementDates())
        {
            return;
        }

        isMovementStatusError = false;
        RaisePropertyChanged(nameof(MovementStatusBrush));
        MovementStatusMessage = "Cargando movimientos...";

        try
        {
            string query = BuildQuery([
                ("search", MovementSearchBox.Text),
                ("type", SelectedMovementType.Value),
                ("warehouse_id", SelectedMovementWarehouse.Id?.ToString()),
                ("date_from", MovementDateFromBox.Text),
                ("date_to", MovementDateToBox.Text),
                ("limit", "24"),
                ("page", page.ToString()),
            ]);

            InventoryProductMovementsPageResponse response = await apiClient.GetAsync<InventoryProductMovementsPageResponse>(
                $"inventory-center/products/{detail.Product.Id}/movements{query}");

            MovementRows.Clear();
            foreach (InventoryProductMovement movement in response.Data.Data)
            {
                MovementRows.Add(movement);
            }

            movementPagination = response.Data.Pagination;
            movementsLoaded = true;
            MovementStatusMessage = PaginationMessage(movementPagination, "movimientos");
            RaiseMovementPaginationChanged();
        }
        catch (ApiException exception)
        {
            SetMovementError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetMovementError("No se pudo conectar con la API para cargar movimientos.");
        }
        catch (TaskCanceledException)
        {
            SetMovementError("La carga de movimientos tardó demasiado. Intenta nuevamente.");
        }
    }

    private async Task LoadAuditsAsync(int page)
    {
        isAuditStatusError = false;
        RaisePropertyChanged(nameof(AuditStatusBrush));
        AuditStatusMessage = "Cargando auditoría...";

        try
        {
            string query = BuildQuery([
                ("search", AuditSearchBox.Text),
                ("action", SelectedAuditAction.Value),
                ("limit", "24"),
                ("page", page.ToString()),
            ]);

            InventoryProductAuditsPageResponse response = await apiClient.GetAsync<InventoryProductAuditsPageResponse>(
                $"inventory-center/products/{detail.Product.Id}/audits{query}");

            AuditRows.Clear();
            foreach (InventoryProductAudit audit in response.Data.Data)
            {
                AuditRows.Add(audit);
            }

            auditPagination = response.Data.Pagination;
            auditsLoaded = true;
            AuditStatusMessage = PaginationMessage(auditPagination, "registros de auditoría");
            RaiseAuditPaginationChanged();
        }
        catch (ApiException exception)
        {
            SetAuditError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetAuditError("No se pudo conectar con la API para cargar auditoría.");
        }
        catch (TaskCanceledException)
        {
            SetAuditError("La carga de auditoría tardó demasiado. Intenta nuevamente.");
        }
    }

    private async Task LoadPricesAsync()
    {
        isPriceStatusError = false;
        RaisePropertyChanged(nameof(PriceStatusBrush));
        PriceStatusMessage = "Cargando precios por lista...";

        try
        {
            PriceListListResponse priceListsResponse = await apiClient.GetAsync<PriceListListResponse>("price-lists?active_only=1");
            ProductPriceListResponse pricesResponse = await apiClient.GetAsync<ProductPriceListResponse>($"products/{detail.Product.Id}/prices");
            ExchangeRateTypeListResponse rateResponse = await apiClient.GetAsync<ExchangeRateTypeListResponse>("currency/rate-types");

            PriceRateTypes.Clear();
            foreach (ExchangeRateTypeOption rateType in rateResponse.Data.Where(rateType => rateType.IsActive))
            {
                PriceRateTypes.Add(rateType);
            }

            ProductPriceRows.Clear();
            foreach (PriceListOption priceList in priceListsResponse.Data)
            {
                ProductPriceOption? current = pricesResponse.Data.FirstOrDefault(price => price.PriceListId == priceList.Id);
                ProductPriceRows.Add(new ProductPriceEditRow(
                    priceList,
                    current,
                    PriceCurrencyOptions,
                    PriceRateTypes,
                    detail.Product.BasePrice,
                    detail.Product.SaleCurrency));
            }

            pricesLoaded = true;
            PriceStatusMessage = ProductPriceRows.Count == 0
                ? "No hay listas activas para asignar precios."
                : $"{ProductPriceRows.Count} listas disponibles. Usa Ver historial para revisar cambios anteriores.";
        }
        catch (ApiException exception)
        {
            SetPriceError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetPriceError("No se pudo conectar con la API para cargar precios.");
        }
        catch (TaskCanceledException)
        {
            SetPriceError("La carga de precios tardó demasiado. Intenta nuevamente.");
        }
    }

    private async Task SavePricesAsync()
    {
        if (!TryBuildProductPrices(out ProductPricesSyncRequest? request))
        {
            return;
        }

        isPriceStatusError = false;
        RaisePropertyChanged(nameof(PriceStatusBrush));
        PriceStatusMessage = "Guardando precios por lista...";

        try
        {
            await apiClient.PutAsync<ProductPricesSyncRequest, ProductPriceListResponse>($"products/{detail.Product.Id}/prices", request!);
            pricesLoaded = false;
            await LoadPricesAsync();
            ProductChanged?.Invoke(this, EventArgs.Empty);
            PriceStatusMessage = "Precios guardados correctamente.";
        }
        catch (ApiException exception)
        {
            SetPriceError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetPriceError("No se pudo conectar con la API para guardar precios.");
        }
        catch (TaskCanceledException)
        {
            SetPriceError("El guardado de precios tardó demasiado. Intenta nuevamente.");
        }
    }

    private bool TryBuildProductPrices(out ProductPricesSyncRequest? request)
    {
        request = null;
        List<ProductPriceSyncItemRequest> prices = [];

        foreach (ProductPriceEditRow row in ProductPriceRows)
        {
            if (string.IsNullOrWhiteSpace(row.PriceText))
            {
                continue;
            }

            if (!decimal.TryParse(row.PriceText, NumberStyles.Number, CultureInfo.CurrentCulture, out decimal price)
                && !decimal.TryParse(row.PriceText, NumberStyles.Number, CultureInfo.InvariantCulture, out price))
            {
                SetPriceError($"El precio de {row.PriceListName} no es válido.");
                return false;
            }

            if (price < 0)
            {
                SetPriceError($"El precio de {row.PriceListName} no puede ser negativo.");
                return false;
            }

            prices.Add(new ProductPriceSyncItemRequest(
                row.PriceListId,
                price,
                row.SelectedCurrency.Value,
                row.SelectedRateType?.Id,
                row.IsActive));
        }

        if (prices.Count == 0)
        {
            SetPriceError("Debes indicar al menos un precio para guardar.");
            return false;
        }

        request = new ProductPricesSyncRequest(prices);
        return true;
    }

    private static string BuildQuery(IEnumerable<(string Key, string? Value)> values)
    {
        List<string> parts = values
            .Where(value => !string.IsNullOrWhiteSpace(value.Value))
            .Select(value => $"{Uri.EscapeDataString(value.Key)}={Uri.EscapeDataString(value.Value!.Trim())}")
            .ToList();

        return parts.Count == 0 ? string.Empty : "?" + string.Join("&", parts);
    }

    private static string PaginationMessage(InventoryPagination pagination, string label)
    {
        return pagination.Total == 0
            ? $"Sin {label} para mostrar."
            : $"{pagination.From}-{pagination.To} de {pagination.Total} {label}.";
    }

    private void SetSerialError(string message)
    {
        isSerialStatusError = true;
        RaisePropertyChanged(nameof(SerialStatusBrush));
        SerialStatusMessage = message;
        serialPagination = new InventoryPagination(1, 24, 0, 1, 0, 0, false, false);
        RaiseSerialPaginationChanged();
    }

    private void SetMovementError(string message)
    {
        isMovementStatusError = true;
        RaisePropertyChanged(nameof(MovementStatusBrush));
        MovementStatusMessage = message;
        movementPagination = new InventoryPagination(1, 24, 0, 1, 0, 0, false, false);
        RaiseMovementPaginationChanged();
    }

    private void SetAuditError(string message)
    {
        isAuditStatusError = true;
        RaisePropertyChanged(nameof(AuditStatusBrush));
        AuditStatusMessage = message;
        auditPagination = new InventoryPagination(1, 24, 0, 1, 0, 0, false, false);
        RaiseAuditPaginationChanged();
    }

    private void SetPriceError(string message)
    {
        isPriceStatusError = true;
        RaisePropertyChanged(nameof(PriceStatusBrush));
        PriceStatusMessage = message;
    }

    private bool ValidateMovementDates()
    {
        if (!TryReadDate(MovementDateFromBox.Text, out DateOnly? from))
        {
            SetMovementError("La fecha desde debe tener formato yyyy-mm-dd.");
            return false;
        }

        if (!TryReadDate(MovementDateToBox.Text, out DateOnly? to))
        {
            SetMovementError("La fecha hasta debe tener formato yyyy-mm-dd.");
            return false;
        }

        if (from.HasValue && to.HasValue && to.Value < from.Value)
        {
            SetMovementError("La fecha hasta no puede ser menor que la fecha desde.");
            return false;
        }

        return true;
    }

    private static bool TryReadDate(string value, out DateOnly? date)
    {
        date = null;
        if (string.IsNullOrWhiteSpace(value))
        {
            return true;
        }

        if (DateOnly.TryParseExact(value.Trim(), "yyyy-MM-dd", out DateOnly parsed))
        {
            date = parsed;
            return true;
        }

        return false;
    }

    private void RaiseSerialPaginationChanged()
    {
        RaisePropertyChanged(nameof(CanGoPreviousSerials));
        RaisePropertyChanged(nameof(CanGoNextSerials));
    }

    private void RaiseMovementPaginationChanged()
    {
        RaisePropertyChanged(nameof(CanGoPreviousMovements));
        RaisePropertyChanged(nameof(CanGoNextMovements));
    }

    private void RaiseAuditPaginationChanged()
    {
        RaisePropertyChanged(nameof(CanGoPreviousAudits));
        RaisePropertyChanged(nameof(CanGoNextAudits));
    }

    private static void ShowOpenError(string actionName, Exception exception)
    {
        MessageBox.Show(
            $"No se pudo abrir la ventana de {actionName}.\n\n{exception.Message}",
            "Sistema de Inventario",
            MessageBoxButton.OK,
            MessageBoxImage.Error);
    }

    private void RefreshWarehouseFilters()
    {
        long? serialWarehouseId = SelectedSerialWarehouse.Id;
        long? movementWarehouseId = SelectedMovementWarehouse.Id;

        WarehouseFilterOptions.Clear();
        WarehouseFilterOptions.Add(WarehouseFilterOption.All);
        foreach (InventoryWarehouseStock warehouse in detail.Stock.ByWarehouse)
        {
            WarehouseFilterOptions.Add(new WarehouseFilterOption(warehouse.WarehouseId, warehouse.WarehouseLabel));
        }

        SelectedSerialWarehouse = WarehouseFilterOptions.FirstOrDefault(option => option.Id == serialWarehouseId) ?? WarehouseFilterOption.All;
        SelectedMovementWarehouse = WarehouseFilterOptions.FirstOrDefault(option => option.Id == movementWarehouseId) ?? WarehouseFilterOption.All;
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
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

public sealed record WarehouseFilterOption(long? Id, string Label)
{
    public static WarehouseFilterOption All { get; } = new(null, "Todos los almacenes");
}

public sealed class ProductPriceEditRow : INotifyPropertyChanged
{
    private readonly IReadOnlyList<ProductOption> currencies;
    private readonly decimal? basePrice;
    private readonly string baseCurrency;
    private string priceText = "";
    private ProductOption selectedCurrency;
    private ExchangeRateTypeOption? selectedRateType;
    private bool isActive = true;

    public ProductPriceEditRow(
        PriceListOption priceList,
        ProductPriceOption? current,
        IReadOnlyList<ProductOption> currencies,
        IEnumerable<ExchangeRateTypeOption> rateTypes,
        decimal? basePrice,
        string baseCurrency)
    {
        this.currencies = currencies;
        this.basePrice = basePrice;
        this.baseCurrency = string.IsNullOrWhiteSpace(baseCurrency) ? "USD" : baseCurrency;
        PriceListId = priceList.Id;
        PriceListName = priceList.Name;
        PriceListCode = priceList.Code;
        priceText = current is null ? "" : current.Price.ToString("0.##", CultureInfo.CurrentCulture);
        selectedCurrency = currencies.FirstOrDefault(option => option.Value == current?.Currency) ?? ProductOption.UsdCurrency;
        selectedRateType = rateTypes.FirstOrDefault(option => option.Id == current?.ExchangeRateTypeId);
        isActive = current?.IsActive ?? true;
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public long PriceListId { get; }

    public string PriceListName { get; }

    public string PriceListCode { get; }

    public string PriceText
    {
        get => priceText;
        set
        {
            if (SetProperty(ref priceText, value))
            {
                RaisePricePreviewChanged();
            }
        }
    }

    public ProductOption SelectedCurrency
    {
        get => selectedCurrency;
        set
        {
            if (SetProperty(ref selectedCurrency, value))
            {
                RaisePricePreviewChanged();
            }
        }
    }

    public ExchangeRateTypeOption? SelectedRateType
    {
        get => selectedRateType;
        set => SetProperty(ref selectedRateType, value);
    }

    public bool IsActive
    {
        get => isActive;
        set
        {
            if (SetProperty(ref isActive, value))
            {
                RaisePricePreviewChanged();
            }
        }
    }

    public bool HasTypedPrice => !string.IsNullOrWhiteSpace(PriceText);

    public string PosPriceLabel
    {
        get
        {
            if (TryReadTypedPrice(out decimal typedPrice) && IsActive)
            {
                return $"{SelectedCurrency.Value} {typedPrice:0.##}";
            }

            if (HasTypedPrice && !TryReadTypedPrice(out _))
            {
                return "Precio inválido";
            }

            return basePrice is null ? "Sin precio" : $"{baseCurrency} {basePrice.Value:0.##}";
        }
    }

    public string PriceSourceLabel
    {
        get
        {
            if (TryReadTypedPrice(out _) && IsActive)
            {
                return "Precio específico de la lista";
            }

            if (HasTypedPrice && !TryReadTypedPrice(out _))
            {
                return "Revisa el monto antes de guardar";
            }

            if (HasTypedPrice && !IsActive)
            {
                return "Lista inactiva: usará precio base";
            }

            return basePrice is null ? "Sin precio base" : "Respaldo: precio base";
        }
    }

    public Brush PriceSourceBrush
    {
        get
        {
            if (TryReadTypedPrice(out _) && IsActive)
            {
                return new SolidColorBrush(Color.FromRgb(4, 120, 87));
            }

            if (HasTypedPrice && !TryReadTypedPrice(out _))
            {
                return new SolidColorBrush(Color.FromRgb(217, 54, 92));
            }

            if (basePrice is null)
            {
                return new SolidColorBrush(Color.FromRgb(217, 54, 92));
            }

            return new SolidColorBrush(Color.FromRgb(100, 113, 140));
        }
    }

    public void CopyBasePrice()
    {
        if (basePrice is null)
        {
            return;
        }

        PriceText = basePrice.Value.ToString("0.##", CultureInfo.CurrentCulture);
        SelectedCurrency = currencies.FirstOrDefault(option => option.Value == baseCurrency) ?? SelectedCurrency;
        IsActive = true;
    }

    private bool TryReadTypedPrice(out decimal price)
    {
        return decimal.TryParse(PriceText, NumberStyles.Number, CultureInfo.CurrentCulture, out price)
            || decimal.TryParse(PriceText, NumberStyles.Number, CultureInfo.InvariantCulture, out price);
    }

    private void RaisePricePreviewChanged()
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(HasTypedPrice)));
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(PosPriceLabel)));
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(PriceSourceLabel)));
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(PriceSourceBrush)));
    }

    private bool SetProperty<T>(ref T field, T value, [CallerMemberName] string? propertyName = null)
    {
        if (EqualityComparer<T>.Default.Equals(field, value))
        {
            return false;
        }

        field = value;
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
        return true;
    }
}
