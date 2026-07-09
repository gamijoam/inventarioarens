using System.Collections.ObjectModel;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.InventoryTransfers;

public sealed class InventoryTransferReceptionViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private InventoryTransferItem? selectedTransfer;
    private bool isBusy;
    private bool isStatusError;
    private string statusMessage = "Carga las guias despachadas para comenzar.";

    public InventoryTransferReceptionViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
    }

    public ObservableCollection<InventoryTransferItem> Transfers { get; } = new();

    public ObservableCollection<InventoryTransferReceptionLine> Lines { get; } = new();

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
                RaisePropertyChanged(nameof(CanReceive));
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

    public bool CanReceive => !IsBusy && SelectedTransfer is not null && Lines.Count > 0;

    public string CountLabel => $"{Transfers.Count} guia(s)";

    public string SelectedTitle => SelectedTransfer is null
        ? "Selecciona una guia"
        : $"{SelectedTransfer.GuideNumber} - {SelectedTransfer.DocumentNumber}";

    public string SelectedRoute => SelectedTransfer?.RouteLabel ?? "No hay guia seleccionada.";

    public string SelectedSummary => SelectedTransfer is null
        ? "Selecciona una guia despachada para revisar lo recibido."
        : $"{SelectedTransfer.ItemsLabel} · {SelectedTransfer.StatusLabel}";

    public async Task LoadAsync()
    {
        if (IsBusy)
        {
            return;
        }

        IsBusy = true;
        SetStatus("Cargando guias despachadas...", false);

        try
        {
            InventoryTransferListResponse response = await apiClient.GetAsync<InventoryTransferListResponse>(
                "inventory-transfers?status=dispatched&validation_mode=logistics");

            long? previousId = SelectedTransfer?.Id;
            Transfers.Clear();
            foreach (InventoryTransferItem transfer in response.Data)
            {
                Transfers.Add(transfer);
            }

            RaisePropertyChanged(nameof(CountLabel));
            RaisePropertyChanged(nameof(HasNoTransfers));

            SelectedTransfer = previousId is null
                ? Transfers.FirstOrDefault()
                : Transfers.FirstOrDefault(transfer => transfer.Id == previousId.Value) ?? Transfers.FirstOrDefault();

            SetStatus(Transfers.Count == 0
                ? "No hay guias despachadas pendientes por recibir."
                : $"{Transfers.Count} guia(s) pendiente(s) de recepcion.", false);
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

    public void ReceiveComplete()
    {
        foreach (InventoryTransferReceptionLine line in Lines)
        {
            line.ReceivedQuantity = line.ExpectedQuantity;
            line.DifferenceReason = string.Empty;
            line.DifferenceNotes = string.Empty;
        }

        SetStatus("Guia preparada para recepcion completa. Confirma para aplicar.", false);
    }

    public async Task<bool> ConfirmReceptionAsync()
    {
        if (SelectedTransfer is null || IsBusy)
        {
            return false;
        }

        InventoryTransferReceptionLine? missingReason = Lines.FirstOrDefault(line =>
            line.HasDifference && string.IsNullOrWhiteSpace(line.DifferenceReason));

        if (missingReason is not null)
        {
            SetStatus($"Debes indicar motivo para la diferencia de {missingReason.ProductName}.", true);
            return false;
        }

        IsBusy = true;
        SetStatus("Confirmando recepcion...", false);

        try
        {
            ReceiveInventoryTransferRequest request = new(
                Lines.Select(line => new ReceiveInventoryTransferLineRequest(
                    line.ItemId,
                    line.ReceivedQuantity,
                    line.BuildReceivedUnitIds(),
                    string.IsNullOrWhiteSpace(line.DifferenceReason) ? null : line.DifferenceReason.Trim(),
                    string.IsNullOrWhiteSpace(line.DifferenceNotes) ? null : line.DifferenceNotes.Trim()
                )).ToList());

            await apiClient.PostAsync<ReceiveInventoryTransferRequest, InventoryTransferResponse>(
                $"inventory-transfers/{SelectedTransfer.Id}/receive",
                request);

            SetStatus("Recepcion confirmada correctamente.", false);
            await LoadAsync();
            return true;
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo confirmar la recepcion.", true);
            return false;
        }
        finally
        {
            IsBusy = false;
        }
    }

    private void BuildLines()
    {
        Lines.Clear();

        if (SelectedTransfer is not null)
        {
            foreach (InventoryTransferLine item in SelectedTransfer.Items)
            {
                Lines.Add(new InventoryTransferReceptionLine(item));
            }
        }

        RaisePropertyChanged(nameof(CanReceive));
    }

    private void SetStatus(string message, bool isError)
    {
        isStatusError = isError;
        StatusMessage = message;
        RaisePropertyChanged(nameof(StatusBrush));
    }

    private void RaiseSelectedProperties()
    {
        RaisePropertyChanged(nameof(SelectedTitle));
        RaisePropertyChanged(nameof(SelectedRoute));
        RaisePropertyChanged(nameof(SelectedSummary));
        RaisePropertyChanged(nameof(CanReceive));
    }
}

public sealed class InventoryTransferReceptionLine : ViewModelBase
{
    private decimal receivedQuantity;
    private string differenceReason = string.Empty;
    private string differenceNotes = string.Empty;

    public InventoryTransferReceptionLine(InventoryTransferLine item)
    {
        ItemId = item.Id;
        ProductName = item.Product?.Name ?? $"Producto #{item.ProductId}";
        Sku = item.Product?.Sku ?? "-";
        TrackingLabel = item.Product?.TrackingLabel ?? "Sin control";
        IsSerialized = item.Product?.TrackingType == "serialized";
        ExpectedQuantity = item.PreparedQuantity ?? item.Quantity;
        receivedQuantity = ExpectedQuantity;
        PreparedUnitIds = item.PreparedProductUnitIds ?? Array.Empty<long>();
    }

    public long ItemId { get; }

    public string ProductName { get; }

    public string Sku { get; }

    public string TrackingLabel { get; }

    public bool IsSerialized { get; }

    public decimal ExpectedQuantity { get; }

    public IReadOnlyList<long> PreparedUnitIds { get; }

    public decimal ReceivedQuantity
    {
        get => receivedQuantity;
        set
        {
            decimal normalized = value < 0 ? 0 : value;
            if (SetProperty(ref receivedQuantity, normalized))
            {
                RaisePropertyChanged(nameof(DifferenceQuantity));
                RaisePropertyChanged(nameof(HasDifference));
                RaisePropertyChanged(nameof(ReceivedLabel));
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

    public decimal DifferenceQuantity => ExpectedQuantity - ReceivedQuantity;

    public bool HasDifference => DifferenceQuantity != 0;

    public string ExpectedLabel => ExpectedQuantity.ToString("0.####");

    public string ReceivedLabel => ReceivedQuantity.ToString("0.####");

    public string SerialSummary => IsSerialized
        ? $"{PreparedUnitIds.Count} IMEI(s) despachado(s)"
        : "Producto por cantidad";

    public IReadOnlyList<long>? BuildReceivedUnitIds()
    {
        if (!IsSerialized)
        {
            return null;
        }

        decimal safeQuantity = Math.Max(ReceivedQuantity, 0);
        int count = (int)Math.Min(safeQuantity, PreparedUnitIds.Count);
        return PreparedUnitIds.Take(count).ToList();
    }
}
