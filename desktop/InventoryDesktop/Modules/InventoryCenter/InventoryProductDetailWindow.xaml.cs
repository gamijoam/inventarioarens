using System.Collections.ObjectModel;
using System.ComponentModel;
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
    private string serialStatusMessage = "Abre esta pestaña para cargar seriales/IMEI.";
    private string movementStatusMessage = "Abre esta pestaña para cargar movimientos.";
    private bool isSerialStatusError;
    private bool isMovementStatusError;
    private bool serialsLoaded;
    private bool movementsLoaded;
    private FilterOption selectedSerialStatus = new("all", "Todos");
    private FilterOption selectedMovementType = new("all", "Todos");

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

    public Brush SerialStatusBrush => isSerialStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public Brush MovementStatusBrush => isMovementStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

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
    }

    private async void SearchSerials_Click(object sender, RoutedEventArgs e)
    {
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
        SerialRows.Clear();
        MovementRows.Clear();
        SerialStatusMessage = "Seriales pendientes por recargar.";
        MovementStatusMessage = "Movimientos pendientes por recargar.";
        ProductChanged?.Invoke(this, EventArgs.Empty);
    }

    private async Task RefreshDetailAsync()
    {
        try
        {
            InventoryProductDetailResponse response = await apiClient.GetAsync<InventoryProductDetailResponse>(
                $"inventory-center/products/{detail.Product.Id}");

            Detail = response.Data;
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
        isMovementStatusError = false;
        RaisePropertyChanged(nameof(MovementStatusBrush));
        MovementStatusMessage = "Cargando movimientos...";

        try
        {
            string query = BuildQuery([
                ("search", MovementSearchBox.Text),
                ("type", SelectedMovementType.Value),
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
    }

    private void SetMovementError(string message)
    {
        isMovementStatusError = true;
        RaisePropertyChanged(nameof(MovementStatusBrush));
        MovementStatusMessage = message;
    }

    private static void ShowOpenError(string actionName, Exception exception)
    {
        MessageBox.Show(
            $"No se pudo abrir la ventana de {actionName}.\n\n{exception.Message}",
            "Sistema de Inventario",
            MessageBoxButton.OK,
            MessageBoxImage.Error);
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
