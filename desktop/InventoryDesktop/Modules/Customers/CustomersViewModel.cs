using System.Collections.ObjectModel;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.Customers;

public sealed class CustomersViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private CustomerItem? selectedCustomer;
    private CustomerHistory? selectedHistory;
    private string search = string.Empty;
    private bool activeOnly = true;
    private bool isBusy;
    private bool isStatusError;
    private string statusMessage = "Carga clientes para comenzar.";

    public CustomersViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
    }

    public ObservableCollection<CustomerItem> Customers { get; } = new();

    public ObservableCollection<CustomerRecentOrder> RecentOrders { get; } = new();

    public CustomerItem? SelectedCustomer
    {
        get => selectedCustomer;
        set
        {
            if (SetProperty(ref selectedCustomer, value))
            {
                RaiseSelectedProperties();
                _ = LoadSelectedCustomerDetailAsync();
            }
        }
    }

    public CustomerHistory? SelectedHistory
    {
        get => selectedHistory;
        private set
        {
            if (SetProperty(ref selectedHistory, value))
            {
                RaisePropertyChanged(nameof(HistorySummaryLabel));
                RaisePropertyChanged(nameof(HistoryTotalLabel));
                RaisePropertyChanged(nameof(HistoryBalanceLabel));
            }
        }
    }

    public string Search
    {
        get => search;
        set => SetProperty(ref search, value);
    }

    public bool ActiveOnly
    {
        get => activeOnly;
        set => SetProperty(ref activeOnly, value);
    }

    public bool IsBusy
    {
        get => isBusy;
        set => SetProperty(ref isBusy, value);
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => isStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public string CountLabel => $"{Customers.Count} cliente(s)";

    public bool HasSelection => SelectedCustomer is not null;

    public string SelectedName => SelectedCustomer?.Name ?? "Selecciona un cliente";

    public string SelectedDocument => SelectedCustomer?.DocumentLabel ?? "Sin cliente seleccionado";

    public string SelectedContact => SelectedCustomer?.ContactLabel ?? "Selecciona un registro para ver contacto e historial.";

    public string SelectedStatus => SelectedCustomer?.StatusLabel ?? "-";

    public string SelectedAddress => string.IsNullOrWhiteSpace(SelectedCustomer?.FiscalAddress)
        ? "Sin dirección fiscal registrada."
        : SelectedCustomer!.FiscalAddress!;

    public string HistorySummaryLabel => SelectedHistory is null
        ? "Sin historial cargado"
        : $"{SelectedHistory.OrdersLabel} · {SelectedHistory.PaidLabel} · {SelectedHistory.OpenLabel}";

    public string HistoryTotalLabel => SelectedHistory?.TotalLabel ?? "USD 0.00";

    public string HistoryBalanceLabel => SelectedHistory?.BalanceLabel ?? "Saldo USD 0.00";

    public async Task LoadAsync()
    {
        if (IsBusy)
        {
            return;
        }

        IsBusy = true;
        SetStatus("Cargando clientes...", false);

        try
        {
            string query = $"customers?limit=100&active_only={(ActiveOnly ? "1" : "0")}";
            if (!string.IsNullOrWhiteSpace(Search))
            {
                query += $"&search={Uri.EscapeDataString(Search.Trim())}";
            }

            CustomerListResponse response = await apiClient.GetAsync<CustomerListResponse>(query);
            long? previousId = SelectedCustomer?.Id;
            Customers.Clear();
            foreach (CustomerItem customer in response.Data)
            {
                Customers.Add(customer);
            }

            RaisePropertyChanged(nameof(CountLabel));

            SelectedCustomer = previousId is null
                ? Customers.FirstOrDefault()
                : Customers.FirstOrDefault(customer => customer.Id == previousId.Value) ?? Customers.FirstOrDefault();

            SetStatus(Customers.Count == 0 ? "No hay clientes con estos filtros." : $"{Customers.Count} cliente(s) cargados.", false);
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            Customers.Clear();
            SelectedCustomer = null;
            SetStatus(exception is ApiException ? exception.Message : "No se pudieron cargar los clientes.", true);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<CustomerItem?> SaveAsync(CustomerSaveRequest request, long? customerId)
    {
        if (IsBusy)
        {
            return null;
        }

        IsBusy = true;
        SetStatus(customerId is null ? "Creando cliente..." : "Guardando cliente...", false);

        try
        {
            CustomerResponse response = customerId is null
                ? await apiClient.PostAsync<CustomerSaveRequest, CustomerResponse>("customers", request)
                : await apiClient.PatchAsync<CustomerSaveRequest, CustomerResponse>($"customers/{customerId}", request);

            SetStatus("Cliente guardado correctamente.", false);
            return response.Data;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo guardar el cliente.", true);
            return null;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> DeactivateSelectedAsync()
    {
        if (SelectedCustomer is null || IsBusy)
        {
            return false;
        }

        IsBusy = true;
        SetStatus("Desactivando cliente...", false);

        try
        {
            await apiClient.DeleteAsync($"customers/{SelectedCustomer.Id}");
            SetStatus("Cliente desactivado correctamente.", false);
            return true;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo desactivar el cliente.", true);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    private async Task LoadSelectedCustomerDetailAsync()
    {
        RecentOrders.Clear();
        SelectedHistory = null;

        if (SelectedCustomer is null)
        {
            return;
        }

        try
        {
            CustomerDetailResponse response = await apiClient.GetAsync<CustomerDetailResponse>($"customers/{SelectedCustomer.Id}?include=pos_history");
            SelectedHistory = response.Data.PosHistory;

            if (SelectedHistory?.RecentOrders is not null)
            {
                foreach (CustomerRecentOrder order in SelectedHistory.RecentOrders)
                {
                    RecentOrders.Add(order);
                }
            }
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo cargar el historial del cliente.", true);
        }
    }

    private void SetStatus(string message, bool isError)
    {
        isStatusError = isError;
        StatusMessage = message;
        RaisePropertyChanged(nameof(StatusBrush));
    }

    private void RaiseSelectedProperties()
    {
        RaisePropertyChanged(nameof(HasSelection));
        RaisePropertyChanged(nameof(SelectedName));
        RaisePropertyChanged(nameof(SelectedDocument));
        RaisePropertyChanged(nameof(SelectedContact));
        RaisePropertyChanged(nameof(SelectedStatus));
        RaisePropertyChanged(nameof(SelectedAddress));
    }
}
