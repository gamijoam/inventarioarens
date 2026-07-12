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
    private CashRegisterItem? selectedManagedCashRegister;
    private CashRegisterSessionItem? selectedSession;
    private CashRegisterSessionItem? selectedSessionDetail;
    private string newCashRegisterName = "Caja Mostrador 1";
    private string newCashRegisterCode = "CJ-1";
    private string newCashRegisterNotes = "Caja creada desde modulo Caja.";
    private string editCashRegisterName = string.Empty;
    private string editCashRegisterCode = string.Empty;
    private string editCashRegisterStatus = "active";
    private string editCashRegisterNotes = string.Empty;
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

    public ObservableCollection<CashRegisterItem> ActiveCashRegisters { get; } = new();

    public ObservableCollection<CashRegisterSessionItem> Sessions { get; } = new();

    public ObservableCollection<CashRegisterMovementItem> Movements { get; } = new();

    public ObservableCollection<SessionMetricCardItem> SessionMetricItems { get; } = new();

    public ObservableCollection<MovementTimelineItem> MovementTimeline { get; } = new();

    public IReadOnlyList<string> CurrencyOptions { get; } = ["USD", "VES"];

    public IReadOnlyList<string> CashRegisterStatusOptions { get; } = ["active", "inactive"];

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

    public CashRegisterItem? SelectedManagedCashRegister
    {
        get => selectedManagedCashRegister;
        set
        {
            if (SetProperty(ref selectedManagedCashRegister, value))
            {
                LoadManagedCashRegisterIntoForm(value);
                RaisePropertyChanged(nameof(SelectedManagedCashRegisterLabel));
                RaisePropertyChanged(nameof(CanUpdateCashRegister));
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
                selectedSessionDetail = null;
                Movements.Clear();
                RaiseClosingProperties();

                if (value is not null)
                {
                    _ = LoadSelectedSessionDetailAsync(value.Id);
                }
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

    public string EditCashRegisterName
    {
        get => editCashRegisterName;
        set
        {
            if (SetProperty(ref editCashRegisterName, value))
            {
                RaisePropertyChanged(nameof(CanUpdateCashRegister));
            }
        }
    }

    public string EditCashRegisterCode
    {
        get => editCashRegisterCode;
        set
        {
            if (SetProperty(ref editCashRegisterCode, value))
            {
                RaisePropertyChanged(nameof(CanUpdateCashRegister));
            }
        }
    }

    public string EditCashRegisterStatus
    {
        get => editCashRegisterStatus;
        set
        {
            if (SetProperty(ref editCashRegisterStatus, value))
            {
                RaisePropertyChanged(nameof(CanUpdateCashRegister));
            }
        }
    }

    public string EditCashRegisterNotes
    {
        get => editCashRegisterNotes;
        set => SetProperty(ref editCashRegisterNotes, value);
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
                RaisePropertyChanged(nameof(CanUpdateCashRegister));
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

    public bool CanUpdateCashRegister => !IsBusy
        && SelectedManagedCashRegister is not null
        && !string.IsNullOrWhiteSpace(EditCashRegisterName)
        && !string.IsNullOrWhiteSpace(EditCashRegisterCode)
        && CashRegisterStatusOptions.Contains(EditCashRegisterStatus);

    public bool CanOpenCashRegister => !IsBusy
        && SelectedWarehouse?.BranchId is not null
        && SelectedCashRegister is not null;

    public string SelectedManagedCashRegisterLabel => SelectedManagedCashRegister is null
        ? "Selecciona una caja fisica para editarla."
        : $"{SelectedManagedCashRegister.RegisterLabel} - {SelectedManagedCashRegister.BranchLabel}";

    public bool CanCloseCashRegister => !IsBusy && SelectedSession is not null;

    public string SelectedSessionLabel => SelectedSession is null
        ? "Selecciona una caja abierta para cerrar turno."
        : $"{SelectedSession.SessionLabel} - {SelectedSession.BranchLabel}";

    public string ExpectedCloseLabel => SelectedSession is null
        ? "Esperado: USD 0.00 / Bs 0.00"
        : $"Esperado: USD {ActiveSession.ExpectedBaseAmount:0.00} / Bs {ActiveSession.ExpectedLocalAmount:0.00}";

    public string SessionTotalsLabel => SelectedSession is null
        ? "Selecciona un turno para ver el resumen."
        : $"{ActiveSession.SessionLabel} - {Movements.Count} movimiento(s) registrados.";

    public string OpeningSummaryLabel => SelectedSession is null
        ? "USD 0.00 / Bs 0.00"
        : $"USD {ActiveSession.OpeningBaseAmount:0.00} / Bs {ActiveSession.OpeningLocalAmount:0.00}";

    public string PosPaymentsSummaryLabel => $"USD {SumMovementsBase("pos_payment"):0.00} / Bs {SumMovementsLocal("pos_payment"):0.00}";

    public string ManualInflowsSummaryLabel => $"USD {SumMovementsBase("inflow"):0.00} / Bs {SumMovementsLocal("inflow"):0.00}";

    public string ManualOutflowsSummaryLabel => $"USD {SumMovementsBase("outflow"):0.00} / Bs {SumMovementsLocal("outflow"):0.00}";

    public string ExpectedSummaryLabel => SelectedSession is null
        ? "USD 0.00 / Bs 0.00"
        : $"USD {ActiveSession.ExpectedBaseAmount:0.00} / Bs {ActiveSession.ExpectedLocalAmount:0.00}";

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

            decimal difference = CountedAmount - ActiveSession.ExpectedBaseAmount;
            return $"Diferencia estimada: USD {difference:0.00}";
        }
    }

    private CashRegisterSessionItem ActiveSession => selectedSessionDetail ?? SelectedSession!;

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
            LoadSessionMetrics();
            LoadMovementTimeline();
            StatusMessage = "Caja lista para operar. Si no tienes caja abierta, abre una para entrar al POS.";
            IsStatusError = false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task LoadSelectedSessionDetailAsync(long sessionId)
    {
        try
        {
            CashRegisterSessionResponse response = await apiClient.GetAsync<CashRegisterSessionResponse>($"cash-register/sessions/{sessionId}");
            if (SelectedSession?.Id != sessionId)
            {
                return;
            }

            selectedSessionDetail = response.Data;
            Movements.Clear();
            IEnumerable<CashRegisterMovementItem> movements = response.Data.Movements is null
                ? Enumerable.Empty<CashRegisterMovementItem>()
                : response.Data.Movements.OrderByDescending(movement => movement.Id);

            foreach (CashRegisterMovementItem movement in movements)
            {
                Movements.Add(movement);
            }

            CountedAmount = response.Data.ExpectedBaseAmount;
            RaiseClosingProperties();
            StatusMessage = $"Detalle cargado para {response.Data.SessionLabel}. Revisa pagos y conteo antes de cerrar.";
            IsStatusError = false;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (JsonException)
        {
            SetError("La API devolvio el detalle de caja con un formato inesperado. Actualiza e intenta nuevamente.");
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar el detalle de caja.");
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
                .OrderBy(cashRegister => cashRegister.BranchLabel)
                .ThenBy(cashRegister => cashRegister.Name))
            {
                CashRegisters.Add(cashRegister);
            }

            ActiveCashRegisters.Clear();
            foreach (CashRegisterItem cashRegister in CashRegisters.Where(cashRegister => cashRegister.Status == "active"))
            {
                ActiveCashRegisters.Add(cashRegister);
            }

            SelectedCashRegister = ActiveCashRegisters.FirstOrDefault(cashRegister => cashRegister.Id == selectedId);
            SelectedManagedCashRegister = CashRegisters.FirstOrDefault(cashRegister => cashRegister.Id == SelectedManagedCashRegister?.Id)
                ?? CashRegisters.FirstOrDefault();
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
            NewCashRegisterNotes = string.Empty;
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

    public async Task UpdateCashRegisterAsync()
    {
        if (SelectedManagedCashRegister is not CashRegisterItem cashRegister)
        {
            SetError("Selecciona una caja fisica para editar.");
            return;
        }

        if (!CanUpdateCashRegister)
        {
            SetError("Indica nombre, codigo y estado de la caja.");
            return;
        }

        IsBusy = true;
        IsStatusError = false;
        StatusMessage = $"Actualizando {cashRegister.RegisterLabel}...";

        try
        {
            CashRegisterResponse response = await apiClient.PatchAsync<UpdateCashRegisterRequest, CashRegisterResponse>(
                $"cash-register/registers/{cashRegister.Id}",
                new UpdateCashRegisterRequest(
                    EditCashRegisterName.Trim(),
                    EditCashRegisterCode.Trim(),
                    EditCashRegisterStatus,
                    string.IsNullOrWhiteSpace(EditCashRegisterNotes) ? null : EditCashRegisterNotes.Trim()));

            await LoadCashRegistersAsync();
            SelectedManagedCashRegister = CashRegisters.FirstOrDefault(item => item.Id == response.Data.Id) ?? response.Data;
            StatusMessage = $"{response.Data.RegisterLabel} actualizada correctamente.";
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
            SetError("No se pudo conectar con la API para actualizar la caja fisica.");
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

        if (SelectedCashRegister?.BranchId == branchId && SelectedCashRegister.Status == "active")
        {
            return;
        }

        SelectedCashRegister = ActiveCashRegisters.FirstOrDefault(cashRegister => cashRegister.BranchId == branchId);
    }

    private void LoadManagedCashRegisterIntoForm(CashRegisterItem? cashRegister)
    {
        if (cashRegister is null)
        {
            EditCashRegisterName = string.Empty;
            EditCashRegisterCode = string.Empty;
            EditCashRegisterStatus = "active";
            EditCashRegisterNotes = string.Empty;
            return;
        }

        EditCashRegisterName = cashRegister.Name;
        EditCashRegisterCode = cashRegister.Code;
        EditCashRegisterStatus = cashRegister.Status;
        EditCashRegisterNotes = cashRegister.Notes ?? string.Empty;
    }

    private void RaiseClosingProperties()
    {
        RaisePropertyChanged(nameof(CanCloseCashRegister));
        RaisePropertyChanged(nameof(SelectedSessionLabel));
        RaisePropertyChanged(nameof(ExpectedCloseLabel));
        RaisePropertyChanged(nameof(SessionTotalsLabel));
        RaisePropertyChanged(nameof(OpeningSummaryLabel));
        RaisePropertyChanged(nameof(PosPaymentsSummaryLabel));
        RaisePropertyChanged(nameof(ManualInflowsSummaryLabel));
        RaisePropertyChanged(nameof(ManualOutflowsSummaryLabel));
        RaisePropertyChanged(nameof(ExpectedSummaryLabel));
        RaisePropertyChanged(nameof(CountedHelpLabel));
        RaisePropertyChanged(nameof(DifferencePreviewLabel));
    }

    private decimal SumMovementsBase(string type)
    {
        return Movements
            .Where(movement => movement.Type == type)
            .Sum(movement => movement.AmountBase);
    }

    private decimal SumMovementsLocal(string type)
    {
        return Movements
            .Where(movement => movement.Type == type)
            .Sum(movement => movement.AmountLocal ?? 0);
    }

    public void LoadSessionMetrics()
    {
        SessionMetricItems.Clear();
        SessionMetricItems.Add(new SessionMetricCardItem
        {
            Label = "Apertura",
            Value = OpeningSummaryLabel,
            IconKind = MaterialDesignThemes.Wpf.PackIconKind.CashPlus,
            IconColor = new SolidColorBrush(Color.FromRgb(0x4D, 0x35, 0xFF))
        });
        SessionMetricItems.Add(new SessionMetricCardItem
        {
            Label = "Pagos POS",
            Value = PosPaymentsSummaryLabel,
            IconKind = MaterialDesignThemes.Wpf.PackIconKind.CreditCardCheckOutline,
            IconColor = new SolidColorBrush(Color.FromRgb(0x05, 0x96, 0x69))
        });
        SessionMetricItems.Add(new SessionMetricCardItem
        {
            Label = "Entradas",
            Value = ManualInflowsSummaryLabel,
            IconKind = MaterialDesignThemes.Wpf.PackIconKind.ArrowUpBoldCircleOutline,
            IconColor = new SolidColorBrush(Color.FromRgb(0x1D, 0x4E, 0xD8))
        });
        SessionMetricItems.Add(new SessionMetricCardItem
        {
            Label = "Salidas",
            Value = ManualOutflowsSummaryLabel,
            IconKind = MaterialDesignThemes.Wpf.PackIconKind.ArrowDownBoldCircleOutline,
            IconColor = new SolidColorBrush(Color.FromRgb(0xB4, 0x53, 0x09))
        });
        SessionMetricItems.Add(new SessionMetricCardItem
        {
            Label = "Esperado",
            Value = ExpectedSummaryLabel,
            IconKind = MaterialDesignThemes.Wpf.PackIconKind.StarCircleOutline,
            IconColor = new SolidColorBrush(Color.FromRgb(0x3B, 0x2C, 0xF6))
        });
    }

    public void LoadMovementTimeline()
    {
        MovementTimeline.Clear();
        foreach (var movement in Movements.OrderByDescending(m => m.CreatedAt))
        {
            bool isInflow = movement.Type == "inflow" || movement.Type == "pos_payment";
            bool isOutflow = movement.Type == "outflow";
            MovementTimeline.Add(new MovementTimelineItem
            {
                TypeLabel = movement.TypeLabel,
                MethodLabel = movement.MethodLabel,
                AmountLabel = movement.AmountLabel,
                NotesLabel = movement.NotesLabel,
                CreatedAtLabel = movement.CreatedAtLabel,
                IconKind = isInflow
                    ? MaterialDesignThemes.Wpf.PackIconKind.ArrowUpBold
                    : isOutflow
                        ? MaterialDesignThemes.Wpf.PackIconKind.ArrowDownBold
                        : MaterialDesignThemes.Wpf.PackIconKind.SwapHorizontal,
                IconBackground = new SolidColorBrush(isInflow
                    ? Color.FromRgb(0x10, 0xB9, 0x81)
                    : isOutflow
                        ? Color.FromRgb(0xEF, 0x44, 0x44)
                        : Color.FromRgb(0x4D, 0x35, 0xFF)),
                AmountColor = new SolidColorBrush(isInflow
                    ? Color.FromRgb(0x05, 0x96, 0x69)
                    : isOutflow
                        ? Color.FromRgb(0xB9, 0x1C, 0x1C)
                        : Color.FromRgb(0x1E, 0x29, 0x3B))
            });
        }
    }
}

public sealed class SessionMetricCardItem
{
    public string Label { get; set; } = "";
    public string Value { get; set; } = "";
    public MaterialDesignThemes.Wpf.PackIconKind IconKind { get; set; }
    public Brush IconColor { get; set; } = Brushes.Gray;
}

public sealed class MovementTimelineItem
{
    public string TypeLabel { get; set; } = "";
    public string MethodLabel { get; set; } = "";
    public string AmountLabel { get; set; } = "";
    public string NotesLabel { get; set; } = "";
    public string CreatedAtLabel { get; set; } = "";
    public MaterialDesignThemes.Wpf.PackIconKind IconKind { get; set; }
    public Brush IconBackground { get; set; } = Brushes.Gray;
    public Brush AmountColor { get; set; } = Brushes.Gray;
}
