using System.Collections.ObjectModel;
using System.Net.Http;
using System.Text.Json;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.CashRegister;

public sealed class CashRegisterViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private readonly long currentUserId;
    private InventoryWarehouseOption? selectedWarehouse;
    private string openingCurrency = "USD";
    private decimal openingAmount;
    private string notes = "Apertura desde modulo Caja de escritorio.";
    private string statusMessage = "Carga el contexto y abre tu caja para vender en POS.";
    private bool isStatusError;
    private bool isBusy;

    public CashRegisterViewModel(ApiClient apiClient, long currentUserId)
    {
        this.apiClient = apiClient;
        this.currentUserId = currentUserId;
    }

    public ObservableCollection<InventoryWarehouseOption> Warehouses { get; } = new();

    public ObservableCollection<CashRegisterSessionItem> Sessions { get; } = new();

    public IReadOnlyList<string> CurrencyOptions { get; } = ["USD", "VES"];

    public InventoryWarehouseOption? SelectedWarehouse
    {
        get => selectedWarehouse;
        set
        {
            if (SetProperty(ref selectedWarehouse, value))
            {
                RaisePropertyChanged(nameof(SelectedBranchLabel));
                RaisePropertyChanged(nameof(CanOpenCashRegister));
            }
        }
    }

    public string OpeningCurrency
    {
        get => openingCurrency;
        set => SetProperty(ref openingCurrency, value);
    }

    public decimal OpeningAmount
    {
        get => openingAmount;
        set => SetProperty(ref openingAmount, value);
    }

    public string Notes
    {
        get => notes;
        set => SetProperty(ref notes, value);
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
                RaisePropertyChanged(nameof(CanOpenCashRegister));
            }
        }
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public string SelectedBranchLabel => SelectedWarehouse?.BranchName is null
        ? "Selecciona un almacen para detectar la sucursal."
        : $"Sucursal: {SelectedWarehouse.BranchName}";

    public bool CanOpenCashRegister => !IsBusy && SelectedWarehouse?.BranchId is not null;

    public async Task LoadAsync()
    {
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Cargando cajas y almacenes...";

        try
        {
            await LoadWarehousesAsync();
            await LoadSessionsAsync();
            StatusMessage = "Caja lista para operar. Si no tienes caja abierta, abre una para entrar al POS.";
            IsStatusError = false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task LoadSessionsAsync()
    {
        try
        {
            CashRegisterSessionListResponse response = await apiClient.GetAsync<CashRegisterSessionListResponse>("cash-register/sessions");
            Sessions.Clear();
            foreach (CashRegisterSessionItem session in response.Data
                .Where(session => session.Status == "open")
                .OrderByDescending(session => session.Id))
            {
                Sessions.Add(session);
            }
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (JsonException)
        {
            SetError("La API devolvio datos de caja con un formato inesperado. Actualiza e intenta nuevamente.");
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar cajas.");
        }
    }

    public async Task OpenCashRegisterAsync()
    {
        if (SelectedWarehouse?.BranchId is not long branchId)
        {
            SetError("Selecciona un almacen asociado a una sucursal antes de abrir caja.");
            return;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Abriendo tu caja...";

        try
        {
            CashRegisterSessionResponse response = await apiClient.PostAsync<OpenCashRegisterRequest, CashRegisterSessionResponse>(
                "cash-register/sessions",
                new OpenCashRegisterRequest(branchId, OpeningCurrency, OpeningAmount, Notes));

            await LoadSessionsAsync();
            StatusMessage = $"Caja #{response.Data.Id} abierta correctamente. Ya puedes entrar al POS.";
            IsStatusError = false;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (JsonException)
        {
            SetError("La API devolvio la caja abierta con un formato inesperado. Actualiza e intenta nuevamente.");
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para abrir caja.");
        }
        finally
        {
            IsBusy = false;
        }
    }

    private async Task LoadWarehousesAsync()
    {
        try
        {
            WarehouseListResponse response = await apiClient.GetAsync<WarehouseListResponse>("warehouses");
            long? selectedId = SelectedWarehouse?.Id;

            Warehouses.Clear();
            foreach (InventoryWarehouseOption warehouse in response.Data
                .Where(warehouse => warehouse.Status is null || warehouse.Status == "active")
                .OrderBy(warehouse => warehouse.BranchName)
                .ThenBy(warehouse => warehouse.Name))
            {
                Warehouses.Add(warehouse);
            }

            SelectedWarehouse = Warehouses.FirstOrDefault(warehouse => warehouse.Id == selectedId)
                ?? Warehouses.FirstOrDefault();
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (JsonException)
        {
            SetError("La API devolvio almacenes con un formato inesperado. Actualiza e intenta nuevamente.");
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar almacenes.");
        }
    }

    private void SetError(string message)
    {
        IsStatusError = true;
        StatusMessage = message;
    }
}
