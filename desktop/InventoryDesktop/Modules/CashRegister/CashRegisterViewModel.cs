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
    private CashRegisterItem? selectedCashRegister;
    private CashRegisterSessionItem? selectedSession;
    private string newCashRegisterName = "Caja Mostrador 1";
    private string newCashRegisterCode = "CJ-1";
    private string newCashRegisterNotes = "Caja creada desde modulo Caja.";
    private string openingCurrency = "USD";
    private decimal openingAmount;
    private string notes = "Apertura desde modulo Caja de escritorio.";
    private string closingCurrency = "USD";
    private decimal countedAmount;
    private string closingNotes = "Cierre desde modulo Caja de escritorio.";
    private string statusMessage = "Carga el contexto y abre tu caja para vender en POS.";
    private bool isStatusError;
    private bool isBusy;

    public CashRegisterViewModel(ApiClient apiClient, long currentUserId)
    {
        this.apiClient = apiClient;
        this.currentUserId = currentUserId;
    }

    public ObservableCollection<InventoryWarehouseOption> Warehouses { get; } = new();

    public ObservableCollection<CashRegisterItem> CashRegisters { get; } = new();

    public ObservableCollection<CashRegisterSessionItem> Sessions { get; } = new();

    public IReadOnlyList<string> CurrencyOptions { get; } = ["USD", "VES"];

    public InventoryWarehouseOption? SelectedWarehouse
    {
        get => selectedWarehouse;
        set
        {
            if (SetProperty(ref selectedWarehouse, value))
            {
                SelectCashRegisterForWarehouse();
                RaisePropertyChanged(nameof(SelectedBranchLabel));
                RaisePropertyChanged(nameof(CanOpenCashRegister));
                RaisePropertyChanged(nameof(CanCreateCashRegister));
            }
        }
    }

    public CashRegisterItem? SelectedCashRegister
    {
        get => selectedCashRegister;
        set
        {
            if (SetProperty(ref selectedCashRegister, value))
            {
                RaisePropertyChanged(nameof(SelectedCashRegisterLabel));
                RaisePropertyChanged(nameof(CanOpenCashRegister));
            }
        }
    }

    public CashRegisterSessionItem? SelectedSession
    {
        get => selectedSession;
        set
        {
            if (SetProperty(ref selectedSession, value))
            {
                CountedAmount = value?.ExpectedBaseAmount ?? 0;
                ClosingCurrency = "USD";
                RaiseClosingProperties();
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

    public string NewCashRegisterName
    {
        get => newCashRegisterName;
        set
        {
            if (SetProperty(ref newCashRegisterName, value))
            {
                RaisePropertyChanged(nameof(CanCreateCashRegister));
            }
        }
    }

    public string NewCashRegisterCode
    {
        get => newCashRegisterCode;
        set
        {
            if (SetProperty(ref newCashRegisterCode, value))
            {
                RaisePropertyChanged(nameof(CanCreateCashRegister));
            }
        }
    }

    public string NewCashRegisterNotes
    {
        get => newCashRegisterNotes;
        set => SetProperty(ref newCashRegisterNotes, value);
    }

    public string ClosingCurrency
    {
        get => closingCurrency;
        set
        {
            if (SetProperty(ref closingCurrency, value))
            {
                RaiseClosingProperties();
            }
        }
    }

    public decimal CountedAmount
    {
        get => countedAmount;
        set
        {
            if (SetProperty(ref countedAmount, value))
            {
                RaiseClosingProperties();
            }
        }
    }

    public string ClosingNotes
    {
        get => closingNotes;
        set => SetProperty(ref closingNotes, value);
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
                RaisePropertyChanged(nameof(CanCloseCashRegister));
                RaisePropertyChanged(nameof(CanCreateCashRegister));
            }
        }
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public string SelectedBranchLabel => SelectedWarehouse?.BranchName is null
        ? "Selecciona un almacen para detectar la sucursal."
        : $"Sucursal: {SelectedWarehouse.BranchName}";

    public string SelectedCashRegisterLabel => SelectedCashRegister is null
        ? "Selecciona o crea una caja fisica para abrir turno."
        : $"{SelectedCashRegister.RegisterLabel} - {SelectedCashRegister.OpenLabel}";

    public bool CanCreateCashRegister => !IsBusy
        && SelectedWarehouse?.BranchId is not null
        && !string.IsNullOrWhiteSpace(NewCashRegisterName)
        && !string.IsNullOrWhiteSpace(NewCashRegisterCode);

    public bool CanOpenCashRegister => !IsBusy
        && SelectedWarehouse?.BranchId is not null
        && SelectedCashRegister is not null;

    public bool CanCloseCashRegister => !IsBusy && SelectedSession is not null;

    public string SelectedSessionLabel => SelectedSession is null
        ? "Selecciona una caja abierta para cerrar turno."
        : $"{SelectedSession.SessionLabel} - {SelectedSession.BranchLabel}";

    public string ExpectedCloseLabel => SelectedSession is null
        ? "Esperado: USD 0.00 / Bs 0.00"
        : $"Esperado: USD {SelectedSession.ExpectedBaseAmount:0.00} / Bs {SelectedSession.ExpectedLocalAmount:0.00}";

    public string CountedHelpLabel => ClosingCurrency == "VES"
        ? "El conteo en bolivares sera convertido por el backend con la tasa vigente."
        : "El conteo se compara contra el esperado en dolares.";

    public string DifferencePreviewLabel
    {
        get
        {
            if (SelectedSession is null)
            {
                return "Diferencia estimada: USD 0.00";
            }

            if (ClosingCurrency == "VES")
            {
                return "Diferencia estimada: se calculara al cerrar con la tasa vigente.";
            }

            decimal difference = CountedAmount - SelectedSession.ExpectedBaseAmount;
            return $"Diferencia estimada: USD {difference:0.00}";
        }
    }

    public async Task LoadAsync()
    {
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Cargando cajas y almacenes...";

        try
        {
            await LoadWarehousesAsync();
            await LoadCashRegistersAsync();
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

            SelectedSession = Sessions.FirstOrDefault(session => session.Id == SelectedSession?.Id)
                ?? Sessions.FirstOrDefault(session => session.CashierId == currentUserId)
                ?? Sessions.FirstOrDefault();
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

    public async Task LoadCashRegistersAsync()
    {
        try
        {
            CashRegisterListResponse response = await apiClient.GetAsync<CashRegisterListResponse>("cash-register/registers");
            long? selectedId = SelectedCashRegister?.Id;

            CashRegisters.Clear();
            foreach (CashRegisterItem cashRegister in response.Data
                .Where(cashRegister => cashRegister.Status == "active")
                .OrderBy(cashRegister => cashRegister.BranchLabel)
                .ThenBy(cashRegister => cashRegister.Name))
            {
                CashRegisters.Add(cashRegister);
            }

            SelectedCashRegister = CashRegisters.FirstOrDefault(cashRegister => cashRegister.Id == selectedId);
            SelectCashRegisterForWarehouse();
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (JsonException)
        {
            SetError("La API devolvio cajas fisicas con un formato inesperado. Actualiza e intenta nuevamente.");
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar cajas fisicas.");
        }
    }

    public async Task CreateCashRegisterAsync()
    {
        if (SelectedWarehouse?.BranchId is not long branchId)
        {
            SetError("Selecciona un almacen asociado a una sucursal antes de crear la caja.");
            return;
        }

        if (string.IsNullOrWhiteSpace(NewCashRegisterName) || string.IsNullOrWhiteSpace(NewCashRegisterCode))
        {
            SetError("Indica nombre y codigo de la caja.");
            return;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = "Creando caja fisica...";

        try
        {
            CashRegisterResponse response = await apiClient.PostAsync<StoreCashRegisterRequest, CashRegisterResponse>(
                "cash-register/registers",
                new StoreCashRegisterRequest(branchId, NewCashRegisterName.Trim(), NewCashRegisterCode.Trim(), "active", NewCashRegisterNotes));

            await LoadCashRegistersAsync();
            SelectedCashRegister = CashRegisters.FirstOrDefault(cashRegister => cashRegister.Id == response.Data.Id) ?? response.Data;
            NewCashRegisterName = string.Empty;
            NewCashRegisterCode = string.Empty;
            StatusMessage = $"{response.Data.RegisterLabel} creada correctamente. Ya puedes abrir turno en esa caja.";
            IsStatusError = false;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (JsonException)
        {
            SetError("La API devolvio la caja fisica con un formato inesperado. Actualiza e intenta nuevamente.");
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para crear la caja fisica.");
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task OpenCashRegisterAsync()
    {
        if (SelectedWarehouse?.BranchId is not long branchId)
        {
            SetError("Selecciona un almacen asociado a una sucursal antes de abrir caja.");
            return;
        }

        if (SelectedCashRegister is not CashRegisterItem cashRegister)
        {
            SetError("Selecciona una caja fisica antes de abrir turno.");
            return;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Abriendo turno en {cashRegister.RegisterLabel}...";

        try
        {
            CashRegisterSessionResponse response = await apiClient.PostAsync<OpenCashRegisterRequest, CashRegisterSessionResponse>(
                "cash-register/sessions",
                new OpenCashRegisterRequest(branchId, cashRegister.Id, OpeningCurrency, OpeningAmount, Notes));

            await LoadCashRegistersAsync();
            await LoadSessionsAsync();
            StatusMessage = $"{response.Data.SessionLabel} abierta correctamente. Ya puedes entrar al POS.";
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

    public async Task CloseCashRegisterAsync()
    {
        if (SelectedSession is not CashRegisterSessionItem session)
        {
            SetError("Selecciona una caja abierta antes de cerrar.");
            return;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Cerrando {session.SessionLabel}...";

        try
        {
            CashRegisterSessionResponse response = await apiClient.PatchAsync<CloseCashRegisterRequest, CashRegisterSessionResponse>(
                $"cash-register/sessions/{session.Id}/close",
                new CloseCashRegisterRequest(ClosingCurrency, CountedAmount, ClosingNotes));

            await LoadCashRegistersAsync();
            await LoadSessionsAsync();
            StatusMessage = $"Caja #{response.Data.Id} cerrada. Diferencia: USD {response.Data.DifferenceBaseAmount ?? 0:0.00} / Bs {response.Data.DifferenceLocalAmount ?? 0:0.00}.";
            IsStatusError = false;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (JsonException)
        {
            SetError("La API devolvio el cierre de caja con un formato inesperado. Actualiza e intenta nuevamente.");
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cerrar caja.");
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

    private void SelectCashRegisterForWarehouse()
    {
        long? branchId = SelectedWarehouse?.BranchId;
        if (branchId is null)
        {
            SelectedCashRegister = null;
            return;
        }

        if (SelectedCashRegister?.BranchId == branchId)
        {
            return;
        }

        SelectedCashRegister = CashRegisters.FirstOrDefault(cashRegister => cashRegister.BranchId == branchId);
    }

    private void RaiseClosingProperties()
    {
        RaisePropertyChanged(nameof(CanCloseCashRegister));
        RaisePropertyChanged(nameof(SelectedSessionLabel));
        RaisePropertyChanged(nameof(ExpectedCloseLabel));
        RaisePropertyChanged(nameof(CountedHelpLabel));
        RaisePropertyChanged(nameof(DifferencePreviewLabel));
    }
}
