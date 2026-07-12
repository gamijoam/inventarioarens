using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Media;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductKardexWindow : Window, INotifyPropertyChanged
{
    private readonly InventoryProductDetailData detail;
    private readonly ApiClient apiClient;
    private InventoryWarehouseStock? selectedWarehouse;
    private DateTime? dateFrom;
    private DateTime? dateTo;
    private decimal openingBalance;
    private decimal closingBalance;
    private string statusMessage = "Cargando Kardex...";
    private bool isStatusError;
    private bool isBusy;

    public InventoryProductKardexWindow(InventoryProductDetailData detail, ApiClient apiClient)
    {
        this.detail = detail;
        this.apiClient = apiClient;

        ProductName = detail.Product.Name;
        ProductSku = detail.Product.Sku;
        Warehouses.Add(new InventoryWarehouseStock(0, "Todos los almacenes", null, null, 0, 0, 0));
        foreach (InventoryWarehouseStock warehouse in detail.Stock.ByWarehouse)
        {
            Warehouses.Add(warehouse);
        }

        SelectedWarehouse = Warehouses.FirstOrDefault();

        InitializeComponent();
        DataContext = this;
        Title = $"Kardex - {detail.Product.Name}";
        Loaded += async (_, _) => await LoadAsync();
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public string ProductName { get; }

    public string ProductSku { get; }

    public ObservableCollection<InventoryWarehouseStock> Warehouses { get; } = new();

    public ObservableCollection<InventoryProductKardexMovement> Movements { get; } = new();

    public InventoryWarehouseStock? SelectedWarehouse
    {
        get => selectedWarehouse;
        set => SetProperty(ref selectedWarehouse, value);
    }

    public DateTime? DateFrom
    {
        get => dateFrom;
        set => SetProperty(ref dateFrom, value);
    }

    public DateTime? DateTo
    {
        get => dateTo;
        set => SetProperty(ref dateTo, value);
    }

    public decimal OpeningBalance
    {
        get => openingBalance;
        set => SetProperty(ref openingBalance, value);
    }

    public decimal ClosingBalance
    {
        get => closingBalance;
        set => SetProperty(ref closingBalance, value);
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public bool IsStatusError
    {
        get => isStatusError;
        set
        {
            if (SetProperty(ref isStatusError, value))
            {
                RaisePropertyChanged(nameof(StatusBrush));
                RaisePropertyChanged(nameof(EmptyStateTitle));
                RaisePropertyChanged(nameof(EmptyStateMessage));
            }
        }
    }

    public bool IsBusy
    {
        get => isBusy;
        set
        {
            if (SetProperty(ref isBusy, value))
            {
                RaisePropertyChanged(nameof(ShowEmptyState));
            }
        }
    }

    public int MovementCount => Movements.Count;

    public bool ShowEmptyState => !IsBusy && Movements.Count == 0;

    public string EmptyStateTitle => IsStatusError ? "No se pudo cargar el Kardex" : "Sin movimientos en este filtro";

    public string EmptyStateMessage => IsStatusError
        ? "Revisa la conexiÃ³n con la API o los permisos del usuario."
        : "Prueba otro almacÃ©n o rango de fechas para revisar el historial.";

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    private async void Apply_Click(object sender, RoutedEventArgs e)
    {
        await LoadAsync();
    }

    private async void Clear_Click(object sender, RoutedEventArgs e)
    {
        SelectedWarehouse = Warehouses.FirstOrDefault();
        DateFrom = null;
        DateTo = null;
        await LoadAsync();
    }

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        await LoadAsync();
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private async Task LoadAsync()
    {
        if (DateFrom is not null && DateTo is not null && DateTo.Value.Date < DateFrom.Value.Date)
        {
            StatusMessage = "La fecha hasta no puede ser menor que la fecha desde.";
            IsStatusError = true;
            return;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Cargando Kardex...";

        try
        {
            InventoryProductKardexResponse response = await apiClient.GetAsync<InventoryProductKardexResponse>(
                $"kardex/products/{detail.Product.Id}{BuildQuery()}");

            OpeningBalance = response.Data.OpeningBalance;
            ClosingBalance = response.Data.ClosingBalance;

            Movements.Clear();
            foreach (InventoryProductKardexMovement movement in response.Data.Movements)
            {
                Movements.Add(movement);
            }

            RaisePropertyChanged(nameof(MovementCount));
            RaisePropertyChanged(nameof(ShowEmptyState));
            StatusMessage = Movements.Count == 0
                ? "No hay movimientos para el filtro seleccionado."
                : "Kardex actualizado.";
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API para cargar el Kardex.";
            IsStatusError = true;
        }
        catch (TaskCanceledException)
        {
            StatusMessage = "La carga del Kardex tardÃ³ demasiado. Intenta nuevamente.";
            IsStatusError = true;
        }
        finally
        {
            IsBusy = false;
            RaisePropertyChanged(nameof(ShowEmptyState));
            RaisePropertyChanged(nameof(EmptyStateTitle));
            RaisePropertyChanged(nameof(EmptyStateMessage));
        }
    }

    private string BuildQuery()
    {
        List<string> parts = new();

        if (SelectedWarehouse is { WarehouseId: > 0 })
        {
            parts.Add($"warehouse_id={SelectedWarehouse.WarehouseId}");
        }

        if (DateFrom is not null)
        {
            parts.Add($"date_from={DateFrom.Value:yyyy-MM-dd}");
        }

        if (DateTo is not null)
        {
            parts.Add($"date_to={DateTo.Value:yyyy-MM-dd}");
        }

        return parts.Count == 0 ? "" : "?" + string.Join("&", parts);
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

    private void BackToModules_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}

