using System.Collections.ObjectModel;
using System.Globalization;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.InventoryCenter;

public sealed class InventoryCenterViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private string search = "";
    private string trackingType = "";
    private string stockStatus = "all";
    private string statusMessage = "";
    private bool isStatusError;
    private bool isBusy;
    private int page = 1;
    private InventoryCenterMetrics metrics = new(0, 0, 0, 0, 0, 0, 0, 0);
    private InventoryPagination pagination = new(1, 24, 0, 1, 0, 0, false, false);

    public InventoryCenterViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
    }

    public ObservableCollection<InventoryProductItem> Products { get; } = new();

    public IReadOnlyList<FilterOption> TrackingOptions { get; } =
    [
        new("", "Todos"),
        new("quantity", "Por cantidad"),
        new("serialized", "Serializado / IMEI"),
    ];

    public IReadOnlyList<FilterOption> StockOptions { get; } =
    [
        new("all", "Todos"),
        new("available", "Disponibles"),
        new("low", "Stock bajo"),
        new("out", "Sin stock"),
    ];

    public string Search
    {
        get => search;
        set => SetProperty(ref search, value);
    }

    public string TrackingType
    {
        get => trackingType;
        set => SetProperty(ref trackingType, value);
    }

    public string StockStatus
    {
        get => stockStatus;
        set => SetProperty(ref stockStatus, value);
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

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

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

    public InventoryCenterMetrics Metrics
    {
        get => metrics;
        set
        {
            if (SetProperty(ref metrics, value))
            {
                RaiseMetricLabelsChanged();
            }
        }
    }

    public InventoryPagination Pagination
    {
        get => pagination;
        set
        {
            if (SetProperty(ref pagination, value))
            {
                RaisePropertyChanged(nameof(PaginationLabel));
                RaisePropertyChanged(nameof(CanGoPrevious));
                RaisePropertyChanged(nameof(CanGoNext));
            }
        }
    }

    public string TotalProductsLabel => Metrics.TotalProducts.ToString("N0", CultureInfo.CurrentCulture);

    public string AvailableLabel => Metrics.AvailableQuantity.ToString("N0", CultureInfo.CurrentCulture);

    public string ReservedLabel => Metrics.ReservedQuantity.ToString("N0", CultureInfo.CurrentCulture);

    public string DamagedLabel => Metrics.DamagedQuantity.ToString("N0", CultureInfo.CurrentCulture);

    public string LowStockLabel => Metrics.LowStockCount.ToString("N0", CultureInfo.CurrentCulture);

    public string WithoutStockLabel => Metrics.WithoutStockCount.ToString("N0", CultureInfo.CurrentCulture);

    public string PaginationLabel => Pagination.Total == 0
        ? "Sin productos para mostrar"
        : $"{Pagination.From}-{Pagination.To} de {Pagination.Total} productos";

    public bool ShowEmptyState => !IsBusy && Products.Count == 0;

    public string EmptyStateTitle => IsStatusError ? "No se pudo cargar el inventario" : "No hay productos para mostrar";

    public string EmptyStateMessage => IsStatusError
        ? "Revisa el mensaje inferior, confirma que la API esté activa y vuelve a intentar."
        : "Ajusta los filtros o crea productos para comenzar a controlar el stock.";

    public bool CanGoPrevious => Pagination.HasPrevious && !IsBusy;

    public bool CanGoNext => Pagination.HasNext && !IsBusy;

    public async Task LoadAsync()
    {
        await RunAsync(async () =>
        {
            string query = BuildQuery();
            InventoryCenterSummaryResponse response = await apiClient.GetAsync<InventoryCenterSummaryResponse>(
                $"inventory-center/summary{query}");

            Metrics = response.Data.Metrics;
            Pagination = response.Data.Pagination;
            Products.Clear();
            foreach (InventoryProductItem product in response.Data.Products)
            {
                Products.Add(product);
            }

            StatusMessage = "Inventario actualizado.";
            IsStatusError = false;
            RaiseEmptyStateChanged();
        });
    }

    public async Task ApplyFiltersAsync()
    {
        page = 1;
        await LoadAsync();
    }

    public async Task ClearFiltersAsync()
    {
        Search = "";
        TrackingType = "";
        StockStatus = "all";
        page = 1;
        await LoadAsync();
    }

    public async Task PreviousPageAsync()
    {
        if (!CanGoPrevious)
        {
            return;
        }

        page--;
        await LoadAsync();
    }

    public async Task NextPageAsync()
    {
        if (!CanGoNext)
        {
            return;
        }

        page++;
        await LoadAsync();
    }

    private string BuildQuery()
    {
        List<string> parts =
        [
            "limit=24",
            $"page={page}",
        ];

        if (!string.IsNullOrWhiteSpace(Search))
        {
            parts.Add($"search={Uri.EscapeDataString(Search.Trim())}");
        }

        if (!string.IsNullOrWhiteSpace(TrackingType))
        {
            parts.Add($"tracking_type={Uri.EscapeDataString(TrackingType)}");
        }

        if (!string.IsNullOrWhiteSpace(StockStatus) && StockStatus != "all")
        {
            parts.Add($"stock_status={Uri.EscapeDataString(StockStatus)}");
        }

        return "?" + string.Join("&", parts);
    }

    private async Task RunAsync(Func<Task> action)
    {
        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Cargando inventario...";
            await action();
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API. Verifica que Laravel esté encendido.";
            IsStatusError = true;
        }
        catch (TaskCanceledException)
        {
            StatusMessage = "La conexión tardó demasiado. Intenta nuevamente.";
            IsStatusError = true;
        }
        finally
        {
            IsBusy = false;
            RaisePropertyChanged(nameof(CanGoPrevious));
            RaisePropertyChanged(nameof(CanGoNext));
            RaiseEmptyStateChanged();
        }
    }

    private void RaiseMetricLabelsChanged()
    {
        RaisePropertyChanged(nameof(TotalProductsLabel));
        RaisePropertyChanged(nameof(AvailableLabel));
        RaisePropertyChanged(nameof(ReservedLabel));
        RaisePropertyChanged(nameof(DamagedLabel));
        RaisePropertyChanged(nameof(LowStockLabel));
        RaisePropertyChanged(nameof(WithoutStockLabel));
    }

    private void RaiseEmptyStateChanged()
    {
        RaisePropertyChanged(nameof(ShowEmptyState));
        RaisePropertyChanged(nameof(EmptyStateTitle));
        RaisePropertyChanged(nameof(EmptyStateMessage));
    }
}

public sealed record FilterOption(string Value, string Label);
