using System.Collections.ObjectModel;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.InventoryTransfers;

public sealed class InventoryTransferReceptionViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private InventoryTransferStage selectedStage = InventoryTransferStage.Preparation;
    private InventoryTransferItem? selectedTransfer;
    private bool isBusy;
    private bool isStatusError;
    private string statusMessage = "Carga la bandeja de traslados para comenzar.";

    public InventoryTransferReceptionViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
    }

    public ObservableCollection<InventoryTransferItem> Transfers { get; } = new();

    public ObservableCollection<InventoryTransferOperationLine> Lines { get; } = new();

    public InventoryTransferStage SelectedStage
    {
        get => selectedStage;
        set
        {
            if (SetProperty(ref selectedStage, value))
            {
                RaiseStageProperties();
            }
        }
    }

    public InventoryTransferItem? SelectedTransfer
    {
        get => selectedTransfer;
        set
        {
            if (SetProperty(ref selectedTransfer, value))
            {
                BuildLines();
                RaiseSelectedProperties();
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
                RaiseActionProperties();
                RaisePropertyChanged(nameof(HasNoTransfers));
            }
        }
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => isStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public bool HasNoTransfers => !IsBusy && Transfers.Count == 0;

    public bool CanPrepare => !IsBusy && SelectedStage == InventoryTransferStage.Preparation && SelectedTransfer is not null && Lines.Count > 0;

    public bool CanDispatch => !IsBusy && SelectedStage == InventoryTransferStage.Dispatch && SelectedTransfer is not null;

    public bool CanReceive => !IsBusy && SelectedStage == InventoryTransferStage.Reception && SelectedTransfer is not null && Lines.Count > 0;

    public bool CanResolve => !IsBusy
        && SelectedStage == InventoryTransferStage.Resolution
        && SelectedTransfer is not null
        && Lines.Count > 0
        && Lines.All(line => line.IsResolutionReady);

    public bool CanComplete => CanPrepare || CanReceive;

    public bool CanCancel => !IsBusy
        && SelectedTransfer is not null
        && IsTransferCancellable(SelectedTransfer.Status);

    public string CountLabel => $"{Transfers.Count} guia(s)";

    public string StageTitle => SelectedStage switch
    {
        InventoryTransferStage.Preparation => "Preparación logística",
        InventoryTransferStage.Dispatch => "Despacho de guía",
        InventoryTransferStage.Resolution => "Resolución de diferencias",
        _ => "Recepción logística",
    };

    public string StageDescription => SelectedStage switch
    {
        InventoryTransferStage.Preparation => "Marca lo que realmente se cargó y documenta cualquier diferencia antes de reservar el inventario.",
        InventoryTransferStage.Dispatch => "Confirma que la guía preparada salió del almacén origen para enviarla a recepción.",
        InventoryTransferStage.Resolution => "Cierra cada item con diferencia eligiendo aceptar la pérdida, ajustar manualmente o dejarlo en investigación.",
        _ => "Confirma lo recibido en el almacén destino y documenta diferencias antes de cerrar el traslado.",
    };

    public string ListTitle => SelectedStage switch
    {
        InventoryTransferStage.Preparation => "Por preparar",
        InventoryTransferStage.Dispatch => "Por despachar",
        InventoryTransferStage.Resolution => "Con diferencias",
        _ => "Por recibir",
    };

    public string ListHelp => SelectedStage switch
    {
        InventoryTransferStage.Preparation => "Traslados solicitados que esperan checklist de carga.",
        InventoryTransferStage.Dispatch => "Traslados preparados que esperan salida física.",
        InventoryTransferStage.Resolution => "Guías completadas con diferencias que esperan una acción de cierre.",
        _ => "Guías despachadas que esperan recepción.",
    };

    public string EmptyMessage => SelectedStage switch
    {
        InventoryTransferStage.Preparation => "Sin traslados por preparar",
        InventoryTransferStage.Dispatch => "Sin guías por despachar",
        InventoryTransferStage.Resolution => "Sin guías con diferencias",
        _ => "Sin guías por recibir",
    };

    public string EmptyHelp => SelectedStage switch
    {
        InventoryTransferStage.Preparation => "Cuando se solicite un traslado logístico aparecerá aquí.",
        InventoryTransferStage.Dispatch => "Cuando preparación confirme una guía aparecerá aquí.",
        InventoryTransferStage.Resolution => "Cuando una recepción quede con diferencias aparecerá aquí.",
        _ => "Cuando despacho envíe una guía aparecerá aquí.",
    };

    public string SelectedTitle => SelectedTransfer is null
        ? "Selecciona una guía"
        : $"{SelectedTransfer.GuideNumber} - {SelectedTransfer.DocumentNumber}";

    public string SelectedRoute => SelectedTransfer?.RouteLabel ?? "No hay guía seleccionada.";

    public string SelectedSummary => SelectedTransfer is null
        ? "Selecciona una guía para revisar el detalle operativo."
        : $"{SelectedTransfer.ItemsLabel} · {SelectedTransfer.StatusLabel}";

    public async Task LoadAsync()
    {
        if (IsBusy)
        {
            return;
        }

        IsBusy = true;
        SetStatus($"Cargando {ListTitle.ToLowerInvariant()}...", false);

        try
        {
            long? previousId = SelectedTransfer?.Id;
            IReadOnlyList<InventoryTransferItem> transfers = await LoadTransfersForStageAsync();

            Transfers.Clear();
            foreach (InventoryTransferItem transfer in transfers.OrderBy(transfer => transfer.Id))
            {
                Transfers.Add(transfer);
            }

            RaisePropertyChanged(nameof(CountLabel));
            RaisePropertyChanged(nameof(HasNoTransfers));

            SelectedTransfer = previousId is null
                ? Transfers.FirstOrDefault()
                : Transfers.FirstOrDefault(transfer => transfer.Id == previousId.Value) ?? Transfers.FirstOrDefault();

            SetStatus(Transfers.Count == 0
                ? EmptyMessage + "."
                : $"{Transfers.Count} guía(s) en bandeja: {ListTitle.ToLowerInvariant()}.", false);
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            Transfers.Clear();
            SelectedTransfer = null;
            RaisePropertyChanged(nameof(CountLabel));
            RaisePropertyChanged(nameof(HasNoTransfers));
            SetStatus(exception is ApiException ? exception.Message : "No se pudieron cargar los traslados.", true);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task SetStageAsync(InventoryTransferStage stage)
    {
        if (IsBusy || SelectedStage == stage)
        {
            return;
        }

        SelectedStage = stage;
        await LoadAsync();
    }

    public void CompleteCurrentStage()
    {
        foreach (InventoryTransferOperationLine line in Lines)
        {
            line.WorkQuantity = line.ExpectedQuantity;
            line.DifferenceReason = string.Empty;
            line.DifferenceNotes = string.Empty;
        }

        string action = SelectedStage == InventoryTransferStage.Preparation ? "preparación" : "recepción";
        SetStatus($"Guía lista para {action} completa. Confirma para aplicar.", false);
    }

    public async Task<bool> ConfirmPreparationAsync()
    {
        if (!CanPrepare || SelectedTransfer is null)
        {
            return false;
        }

        InventoryTransferOperationLine? invalid = FindLineMissingDifferenceReason();
        if (invalid is not null)
        {
            SetStatus($"Debes indicar motivo para la diferencia de {invalid.ProductName}.", true);
            return false;
        }

        InventoryTransferOperationLine? missingSerials = FindLineMissingSerialSelection();
        if (missingSerials is not null)
        {
            SetStatus($"Debes tildar {missingSerials.WorkQuantity} IMEI(s) para {missingSerials.ProductName}.", true);
            return false;
        }

        IsBusy = true;
        SetStatus("Confirmando preparación...", false);

        try
        {
            PrepareInventoryTransferRequest request = new(
                Lines.Select(line => new PrepareInventoryTransferLineRequest(
                    line.ItemId,
                    line.WorkQuantity,
                    line.BuildPreparedUnitIds(),
                    line.HasDifference ? line.DifferenceReason.Trim() : null,
                    string.IsNullOrWhiteSpace(line.DifferenceNotes) ? null : line.DifferenceNotes.Trim()
                )).ToList());

            await apiClient.PostAsync<PrepareInventoryTransferRequest, InventoryTransferResponse>(
                $"inventory-transfers/{SelectedTransfer.Id}/prepare",
                request);

            SetStatus("Preparación confirmada correctamente.", false);
            await LoadAsync();
            return true;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo confirmar la preparación.", true);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> ConfirmDispatchAsync()
    {
        if (!CanDispatch || SelectedTransfer is null)
        {
            return false;
        }

        IsBusy = true;
        SetStatus("Confirmando despacho...", false);

        try
        {
            DispatchInventoryTransferRequest request = new("Despacho confirmado desde escritorio.");
            await apiClient.PostAsync<DispatchInventoryTransferRequest, InventoryTransferResponse>(
                $"inventory-transfers/{SelectedTransfer.Id}/dispatch",
                request);

            SetStatus("Despacho confirmado correctamente.", false);
            await LoadAsync();
            return true;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo confirmar el despacho.", true);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> ConfirmCancelAsync(string cancellationReason)
    {
        if (!CanCancel || SelectedTransfer is null)
        {
            return false;
        }

        string reason = (cancellationReason ?? string.Empty).Trim();
        if (reason.Length < 5)
        {
            SetStatus("El motivo de cancelacion debe tener al menos 5 caracteres.", true);
            return false;
        }

        IsBusy = true;
        SetStatus("Cancelando traslado...", false);

        try
        {
            CancelInventoryTransferRequest request = new(reason);
            await apiClient.PostAsync<CancelInventoryTransferRequest, InventoryTransferResponse>(
                $"admin-portal/transfers/{SelectedTransfer.Id}/cancel",
                request);

            string guide = string.IsNullOrWhiteSpace(SelectedTransfer.GuideNumber)
                ? SelectedTransfer.DocumentNumber
                : SelectedTransfer.GuideNumber;
            SetStatus($"Traslado {guide} cancelado correctamente.", false);
            await LoadAsync();
            return true;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo cancelar el traslado.", true);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> ConfirmResolutionAsync()
    {
        if (!CanResolve || SelectedTransfer is null)
        {
            return false;
        }

        IsBusy = true;
        SetStatus("Confirmando resolucion de diferencias...", false);

        try
        {
            ResolveInventoryTransferRequest request = new(
                Notes: null,
                Items: Lines.Select(line => line.BuildResolveRequest()).ToList());

            await apiClient.PostAsync<ResolveInventoryTransferRequest, InventoryTransferResponse>(
                $"admin-portal/transfers/{SelectedTransfer.Id}/resolve-differences",
                request);

            string guide = string.IsNullOrWhiteSpace(SelectedTransfer.GuideNumber)
                ? SelectedTransfer.DocumentNumber
                : SelectedTransfer.GuideNumber;
            SetStatus($"Diferencias resueltas en {guide}. Traslado cerrado.", false);
            await LoadAsync();
            return true;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo resolver las diferencias.", true);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    private static bool IsTransferCancellable(string? status)
    {
        if (string.IsNullOrWhiteSpace(status))
        {
            return false;
        }

        return status switch
        {
            "requested" => true,
            "in_preparation" => true,
            "prepared" => true,
            "prepared_with_differences" => true,
            "dispatched" => true,
            "in_reception" => true,
            _ => false,
        };
    }

    public async Task<bool> ConfirmReceptionAsync()
    {
        if (!CanReceive || SelectedTransfer is null)
        {
            return false;
        }

        InventoryTransferOperationLine? invalid = FindLineMissingDifferenceReason();
        if (invalid is not null)
        {
            SetStatus($"Debes indicar motivo para la diferencia de {invalid.ProductName}.", true);
            return false;
        }

        InventoryTransferOperationLine? missingSerials = FindLineMissingSerialSelection();
        if (missingSerials is not null)
        {
            SetStatus($"Debes tildar {missingSerials.WorkQuantity} IMEI(s) para {missingSerials.ProductName}.", true);
            return false;
        }

        IsBusy = true;
        SetStatus("Confirmando recepción...", false);

        try
        {
            ReceiveInventoryTransferRequest request = new(
                Lines.Select(line => new ReceiveInventoryTransferLineRequest(
                    line.ItemId,
                    line.WorkQuantity,
                    line.BuildReceivedUnitIds(),
                    line.HasDifference ? line.DifferenceReason.Trim() : null,
                    string.IsNullOrWhiteSpace(line.DifferenceNotes) ? null : line.DifferenceNotes.Trim()
                )).ToList());

            await apiClient.PostAsync<ReceiveInventoryTransferRequest, InventoryTransferResponse>(
                $"inventory-transfers/{SelectedTransfer.Id}/receive",
                request);

            SetStatus("Recepción confirmada correctamente.", false);
            await LoadAsync();
            return true;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo confirmar la recepción.", true);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    private async Task<IReadOnlyList<InventoryTransferItem>> LoadTransfersForStageAsync()
    {
        if (SelectedStage == InventoryTransferStage.Dispatch)
        {
            InventoryTransferListResponse prepared = await apiClient.GetAsync<InventoryTransferListResponse>(
                "inventory-transfers?status=prepared&validation_mode=logistics");
            InventoryTransferListResponse withDifferences = await apiClient.GetAsync<InventoryTransferListResponse>(
                "inventory-transfers?status=prepared_with_differences&validation_mode=logistics");

            return prepared.Data.Concat(withDifferences.Data).ToList();
        }

        if (SelectedStage == InventoryTransferStage.Resolution)
        {
            InventoryTransferListResponse response = await apiClient.GetAsync<InventoryTransferListResponse>(
                "inventory-transfers?status=completed_with_differences&validation_mode=logistics");
            return response.Data;
        }

        string status = SelectedStage == InventoryTransferStage.Preparation ? "requested" : "dispatched";
        InventoryTransferListResponse response2 = await apiClient.GetAsync<InventoryTransferListResponse>(
            $"inventory-transfers?status={status}&validation_mode=logistics");

        return response2.Data;
    }

    private InventoryTransferOperationLine? FindLineMissingDifferenceReason()
    {
        return Lines.FirstOrDefault(line =>
            line.HasDifference && string.IsNullOrWhiteSpace(line.DifferenceReason));
    }

    private InventoryTransferOperationLine? FindLineMissingSerialSelection()
    {
        return Lines.FirstOrDefault(line =>
            line.IsSerialized
            && line.WorkQuantity > 0
            && !line.HasCompleteSerialSelection);
    }

    private void BuildLines()
    {
        foreach (InventoryTransferOperationLine existing in Lines)
        {
            existing.PropertyChanged -= OnLinePropertyChanged;
        }

        Lines.Clear();

        if (SelectedTransfer is not null)
        {
            foreach (InventoryTransferLine item in SelectedTransfer.Items)
            {
                InventoryTransferOperationLine line = new(item, SelectedStage);
                line.PropertyChanged += OnLinePropertyChanged;
                Lines.Add(line);
            }
        }

        RaiseActionProperties();
    }

    private void OnLinePropertyChanged(object? sender, System.ComponentModel.PropertyChangedEventArgs e)
    {
        if (e.PropertyName is nameof(InventoryTransferOperationLine.IsResolutionReady)
            or nameof(InventoryTransferOperationLine.WorkQuantity)
            or nameof(InventoryTransferOperationLine.DifferenceReason)
            or nameof(InventoryTransferOperationLine.SelectedSerialsCount))
        {
            RaiseActionProperties();
        }
    }

    private void SetStatus(string message, bool isError)
    {
        isStatusError = isError;
        StatusMessage = message;
        RaisePropertyChanged(nameof(StatusBrush));
    }

    public void SetExternalStatus(string message, bool isError)
    {
        SetStatus(message, isError);
    }

    private void RaiseStageProperties()
    {
        RaisePropertyChanged(nameof(StageTitle));
        RaisePropertyChanged(nameof(StageDescription));
        RaisePropertyChanged(nameof(ListTitle));
        RaisePropertyChanged(nameof(ListHelp));
        RaisePropertyChanged(nameof(EmptyMessage));
        RaisePropertyChanged(nameof(EmptyHelp));
        BuildLines();
        RaiseActionProperties();
    }

    private void RaiseSelectedProperties()
    {
        RaisePropertyChanged(nameof(SelectedTitle));
        RaisePropertyChanged(nameof(SelectedRoute));
        RaisePropertyChanged(nameof(SelectedSummary));
        RaisePropertyChanged(nameof(CanCancel));
        RaiseActionProperties();
    }

    private void RaiseActionProperties()
    {
        RaisePropertyChanged(nameof(CanPrepare));
        RaisePropertyChanged(nameof(CanDispatch));
        RaisePropertyChanged(nameof(CanReceive));
        RaisePropertyChanged(nameof(CanComplete));
        RaisePropertyChanged(nameof(CanCancel));
    }
}

public enum InventoryTransferStage
{
    Preparation,
    Dispatch,
    Reception,
    Resolution,
}

public sealed class InventoryTransferOperationLine : ViewModelBase
{
    private readonly System.Collections.ObjectModel.ObservableCollection<InventoryTransferImeiOption> availableSerials = new();
    private readonly HashSet<long> selectedSerialIds = new();
    private decimal workQuantity;
    private string differenceReason = string.Empty;
    private string differenceNotes = string.Empty;
    private string resolutionAction = "accepted_loss";
    private decimal resolutionQuantity;
    private string resolutionNotes = string.Empty;

    public InventoryTransferOperationLine(InventoryTransferLine item, InventoryTransferStage stage)
    {
        ItemId = item.Id;
        ProductId = item.ProductId;
        ProductName = item.Product?.Name ?? $"Producto #{item.ProductId}";
        Sku = item.Product?.Sku ?? "-";
        TrackingLabel = item.Product?.TrackingLabel ?? "Sin control";
        IsSerialized = item.Product?.TrackingType == "serialized";
        ExpectedQuantity = stage == InventoryTransferStage.Reception
            ? item.PreparedQuantity ?? item.Quantity
            : item.Quantity;
        workQuantity = ExpectedQuantity;
        ReceivedQuantity = item.ReceivedQuantity;
        ResolvedDifference = item.DifferenceQuantity;
        CurrentResolutionStatus = item.ResolutionStatus;
        ProductUnitIds = item.ProductUnitIds ?? Array.Empty<long>();
        PreparedUnitIds = item.PreparedProductUnitIds ?? Array.Empty<long>();
        ReceivedUnitIds = item.ReceivedProductUnitIds ?? Array.Empty<long>();
        if (!string.IsNullOrWhiteSpace(item.ResolutionNotes))
        {
            resolutionNotes = item.ResolutionNotes;
        }

        Stage = stage;
        AvailableSerials = availableSerials;
    }

    public long ItemId { get; }

    public long ProductId { get; }

    public string ProductName { get; }

    public string Sku { get; }

    public string TrackingLabel { get; }

    public bool IsSerialized { get; }

    public decimal ExpectedQuantity { get; }

    public IReadOnlyList<long> ProductUnitIds { get; }

    public IReadOnlyList<long> PreparedUnitIds { get; }

    public IReadOnlyList<long> ReceivedUnitIds { get; }

    public decimal? ReceivedQuantity { get; }

    public decimal? ResolvedDifference { get; }

    public string? CurrentResolutionStatus { get; }

    public InventoryTransferStage Stage { get; }

    public System.Collections.ObjectModel.ObservableCollection<InventoryTransferImeiOption> AvailableSerials { get; }

    public int SelectedSerialsCount => selectedSerialIds.Count;

    public int PoolSize => Stage == InventoryTransferStage.Reception ? PreparedUnitIds.Count : ProductUnitIds.Count;

    public bool CanConfigureSerials => IsSerialized
        && PoolSize > 0
        && (Stage == InventoryTransferStage.Preparation || Stage == InventoryTransferStage.Reception);

    public bool HasCompleteSerialSelection => IsSerialized
        ? (int)Math.Truncate(WorkQuantity) == selectedSerialIds.Count
        : true;

    public string SerialsSelectionLabel
    {
        get
        {
            if (!IsSerialized)
            {
                return "No aplica";
            }

            int required = (int)Math.Truncate(Math.Max(WorkQuantity, 0));
            return required == 0
                ? "0 de 0"
                : $"{selectedSerialIds.Count} de {required}";
        }
    }

    public string SerialSummary
    {
        get
        {
            if (!IsSerialized)
            {
                return "Producto por cantidad";
            }

            int count = Stage == InventoryTransferStage.Reception ? PreparedUnitIds.Count : ProductUnitIds.Count;
            return $"{count} IMEI(s)";
        }
    }

    public decimal WorkQuantity
    {
        get => workQuantity;
        set
        {
            decimal normalized = value < 0 ? 0 : value;
            if (SetProperty(ref workQuantity, normalized))
            {
                RaisePropertyChanged(nameof(DifferenceQuantity));
                RaisePropertyChanged(nameof(HasDifference));
                RaisePropertyChanged(nameof(WorkLabel));
                RaisePropertyChanged(nameof(SerialsSelectionLabel));
                RaisePropertyChanged(nameof(HasCompleteSerialSelection));
            }
        }
    }

    public string DifferenceReason
    {
        get => differenceReason;
        set => SetProperty(ref differenceReason, value);
    }

    public string DifferenceNotes
    {
        get => differenceNotes;
        set => SetProperty(ref differenceNotes, value);
    }

    public decimal DifferenceQuantity => ExpectedQuantity - WorkQuantity;

    public bool HasDifference => DifferenceQuantity != 0;

    public string ExpectedLabel => ExpectedQuantity.ToString("0.####");

    public string WorkLabel => WorkQuantity.ToString("0.####");

    public string ReceivedQuantityLabel => ReceivedQuantity?.ToString("0.####") ?? "-";

    public string ResolvedDifferenceLabel => ResolvedDifference?.ToString("0.####") ?? "0";

    public IReadOnlyList<ResolutionActionOption> AvailableResolutionActions { get; } = new List<ResolutionActionOption>
    {
        new("accepted_loss", "Aceptar perdida"),
        new("adjusted_manually", "Ajuste manual"),
        new("investigating", "Investigando"),
    };

    public string ResolutionAction
    {
        get => resolutionAction;
        set
        {
            if (SetProperty(ref resolutionAction, value))
            {
                RaisePropertyChanged(nameof(RequiresResolutionQuantity));
                RaisePropertyChanged(nameof(IsResolutionReady));
            }
        }
    }

    public decimal ResolutionQuantity
    {
        get => resolutionQuantity;
        set
        {
            decimal normalized = value < 0 ? 0 : value;
            if (SetProperty(ref resolutionQuantity, normalized))
            {
                RaisePropertyChanged(nameof(IsResolutionReady));
            }
        }
    }

    public string ResolutionNotes
    {
        get => resolutionNotes;
        set => SetProperty(ref resolutionNotes, value ?? string.Empty);
    }

    public bool RequiresResolutionQuantity =>
        Stage == InventoryTransferStage.Resolution
        && string.Equals(resolutionAction, "adjusted_manually", StringComparison.Ordinal);

    public bool IsResolutionReady
    {
        get
        {
            if (Stage != InventoryTransferStage.Resolution)
            {
                return false;
            }

            if (string.IsNullOrWhiteSpace(resolutionAction))
            {
                return false;
            }

            if (string.Equals(resolutionAction, "adjusted_manually", StringComparison.Ordinal)
                && resolutionQuantity <= 0)
            {
                return false;
            }

            return true;
        }
    }

    public IReadOnlyList<long> GetSelectedSerialIds() => selectedSerialIds.ToList();

    public void SetSelectedSerialIds(IEnumerable<long> ids)
    {
        selectedSerialIds.Clear();
        if (ids is not null)
        {
            foreach (long id in ids)
            {
                selectedSerialIds.Add(id);
            }
        }

        RaiseSerialSelectionChanged();
    }

    public void ResetSelectedSerialIds()
    {
        if (selectedSerialIds.Count == 0)
        {
            return;
        }

        selectedSerialIds.Clear();
        RaiseSerialSelectionChanged();
    }

    public void AutoFillSerialSelection()
    {
        int required = (int)Math.Truncate(Math.Max(WorkQuantity, 0));
        if (required <= 0)
        {
            ResetSelectedSerialIds();
            return;
        }

        selectedSerialIds.Clear();
        foreach (InventoryTransferImeiOption option in availableSerials
            .Where(option => !selectedSerialIds.Contains(option.Id))
            .Take(required))
        {
            selectedSerialIds.Add(option.Id);
        }

        RaiseSerialSelectionChanged();
    }

    public void RaiseSerialSelectionChanged()
    {
        RaisePropertyChanged(nameof(SelectedSerialsCount));
        RaisePropertyChanged(nameof(SerialsSelectionLabel));
        RaisePropertyChanged(nameof(HasCompleteSerialSelection));
    }

    public IReadOnlyList<long>? BuildPreparedUnitIds()
    {
        if (!IsSerialized)
        {
            return null;
        }

        if (selectedSerialIds.Count == 0)
        {
            return Array.Empty<long>();
        }

        return TakeSerials(selectedSerialIds.ToList());
    }

    public IReadOnlyList<long>? BuildReceivedUnitIds()
    {
        if (!IsSerialized)
        {
            return null;
        }

        if (selectedSerialIds.Count == 0)
        {
            return Array.Empty<long>();
        }

        return TakeSerials(selectedSerialIds.ToList());
    }

    private IReadOnlyList<long> TakeSerials(IReadOnlyList<long> ids)
    {
        decimal safeQuantity = Math.Max(WorkQuantity, 0);
        int count = (int)Math.Min(safeQuantity, ids.Count);
        return ids.Take(count).ToList();
    }

    public ResolveInventoryTransferLineRequest BuildResolveRequest()
    {
        decimal? quantity = string.Equals(resolutionAction, "adjusted_manually", StringComparison.Ordinal)
            ? resolutionQuantity
            : null;
        string? notes = string.IsNullOrWhiteSpace(resolutionNotes) ? null : resolutionNotes.Trim();
        return new ResolveInventoryTransferLineRequest(
            InventoryTransferItemId: ItemId,
            Action: resolutionAction,
            Quantity: quantity,
            Notes: notes);
    }
}

public sealed record ResolutionActionOption(string Value, string Label)
{
    public override string ToString() => Label;
}
