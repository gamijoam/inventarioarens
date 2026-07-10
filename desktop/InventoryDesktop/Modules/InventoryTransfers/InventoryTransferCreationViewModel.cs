using System.Collections.ObjectModel;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.InventoryTransfers;

public sealed class InventoryTransferCreationViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private readonly Action<long>? onTransferCreated;
    private ObservableCollection<InventoryWarehouseOption> warehouses = new();
    private InventoryWarehouseOption? selectedOrigin;
    private InventoryWarehouseOption? selectedDestination;
    private string reason = string.Empty;
    private string reference = string.Empty;
    private string notes = string.Empty;
    private bool isBusy;
    private bool isStatusError;
    private string statusMessage = "Carga los almacenes y completa los items del traslado.";
    private TransferCreationLine? selectedLine;

    public InventoryTransferCreationViewModel(ApiClient apiClient, Action<long>? onTransferCreated = null)
    {
        this.apiClient = apiClient;
        this.onTransferCreated = onTransferCreated;
    }

    public ObservableCollection<InventoryWarehouseOption> Warehouses
    {
        get => warehouses;
        private set
        {
            if (SetProperty(ref warehouses, value))
            {
                RaisePropertyChanged(nameof(HasWarehouses));
                RaiseCanSubmit();
            }
        }
    }

    public InventoryWarehouseOption? SelectedOrigin
    {
        get => selectedOrigin;
        set
        {
            if (SetProperty(ref selectedOrigin, value))
            {
                RaiseCanSubmit();
            }
        }
    }

    public InventoryWarehouseOption? SelectedDestination
    {
        get => selectedDestination;
        set
        {
            if (SetProperty(ref selectedDestination, value))
            {
                RaiseCanSubmit();
            }
        }
    }

    public string Reason
    {
        get => reason;
        set => SetProperty(ref reason, value);
    }

    public string Reference
    {
        get => reference;
        set => SetProperty(ref reference, value);
    }

    public string Notes
    {
        get => notes;
        set => SetProperty(ref notes, value);
    }

    public ObservableCollection<TransferCreationLine> Items { get; } = new();

    public TransferCreationLine? SelectedLine
    {
        get => selectedLine;
        set
        {
            if (SetProperty(ref selectedLine, value))
            {
                RaisePropertyChanged(nameof(HasSelectedLine));
            }
        }
    }

    public bool HasSelectedLine => SelectedLine is not null;

    public bool IsBusy
    {
        get => isBusy;
        set
        {
            if (SetProperty(ref isBusy, value))
            {
                RaiseCanSubmit();
                RaisePropertyChanged(nameof(HasNoItems));
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

    public bool HasWarehouses => Warehouses.Count > 0;

    public bool HasNoItems => !IsBusy && Items.Count == 0;

    public bool CanSubmit
    {
        get
        {
            if (IsBusy)
            {
                return false;
            }

            if (SelectedOrigin is null || SelectedDestination is null)
            {
                return false;
            }

            if (Items.Count == 0)
            {
                return false;
            }

            return true;
        }
    }

    public async Task LoadWarehousesAsync()
    {
        if (IsBusy)
        {
            return;
        }

        IsBusy = true;
        SetStatus("Cargando almacenes...", false);
        try
        {
            WarehouseListResponse response = await apiClient.GetAsync<WarehouseListResponse>("warehouses");
            IReadOnlyList<InventoryWarehouseOption> data = response.Data ?? Array.Empty<InventoryWarehouseOption>();
            IReadOnlyList<InventoryWarehouseOption> ordered = data
                .Where(warehouse => !string.Equals(warehouse.Status, "inactive", StringComparison.OrdinalIgnoreCase))
                .OrderBy(warehouse => warehouse.Name)
                .ToList();

            Warehouses = new ObservableCollection<InventoryWarehouseOption>(ordered);
            SetStatus(ordered.Count == 0
                ? "No hay almacenes activos en esta empresa."
                : $"{ordered.Count} almacenes disponibles. Selecciona origen y destino.",
                ordered.Count == 0);
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            Warehouses = new ObservableCollection<InventoryWarehouseOption>();
            SetStatus(exception is ApiException ? exception.Message : "No se pudieron cargar los almacenes.", true);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public void AddItem(TransferProductOption product, decimal quantity)
    {
        if (product is null)
        {
            return;
        }

        if (quantity <= 0)
        {
            return;
        }

        TransferCreationLine? existing = Items.FirstOrDefault(line => line.ProductId == product.Id);
        if (existing is not null)
        {
            existing.Quantity += quantity;
            existing.RaiseQuantityChanged();
        }
        else
        {
            Items.Add(new TransferCreationLine(product, quantity));
        }

        RaisePropertyChanged(nameof(HasNoItems));
        RaiseCanSubmit();
        SetStatus($"{product.DisplayLabel} agregado al traslado. Total: {Items.Count} item(s).", false);
    }

    public void RemoveLine(TransferCreationLine line)
    {
        if (line is null)
        {
            return;
        }

        Items.Remove(line);
        if (ReferenceEquals(SelectedLine, line))
        {
            SelectedLine = null;
        }
        RaisePropertyChanged(nameof(HasNoItems));
        RaiseCanSubmit();
    }

    public async Task<long?> SubmitAsync()
    {
        if (!CanSubmit)
        {
            SetStatus("Completa origen, destino y al menos un item antes de crear el traslado.", true);
            return null;
        }

        if (SelectedOrigin is null || SelectedDestination is null)
        {
            return null;
        }

        if (SelectedOrigin.Id == SelectedDestination.Id)
        {
            SetStatus("El almacen de origen y destino deben ser distintos.", true);
            return null;
        }

        if (Items.Any(line => line.Quantity <= 0))
        {
            SetStatus("Todas las cantidades deben ser mayores a cero.", true);
            return null;
        }

        IsBusy = true;
        SetStatus("Creando traslado...", false);

        CreateInventoryTransferRequest request = new(
            ValidationMode: "logistics",
            FromWarehouseId: SelectedOrigin.Id,
            ToWarehouseId: SelectedDestination.Id,
            Reason: string.IsNullOrWhiteSpace(Reason) ? null : Reason.Trim(),
            Reference: string.IsNullOrWhiteSpace(Reference) ? null : Reference.Trim(),
            Notes: string.IsNullOrWhiteSpace(Notes) ? null : Notes.Trim(),
            Items: Items
                .Select(line => new CreateInventoryTransferItem(line.ProductId, line.Quantity))
                .ToList());

        try
        {
            InventoryTransferResponse response = await apiClient
                .PostAsync<CreateInventoryTransferRequest, InventoryTransferResponse>("inventory-transfers", request);
            long newId = response.Data.Id;
            string guide = string.IsNullOrWhiteSpace(response.Data.GuideNumber) ? response.Data.DocumentNumber : response.Data.GuideNumber;
            SetStatus($"Traslado {guide} creado correctamente.", false);
            onTransferCreated?.Invoke(newId);
            return newId;
        }
        catch (ApiException apiException)
        {
            SetStatus(BuildApiErrorMessage(apiException), true);
            return null;
        }
        catch (Exception exception) when (exception is HttpRequestException or TaskCanceledException)
        {
            SetStatus("No se pudo crear el traslado. Verifica la conexion con el servidor.", true);
            return null;
        }
        finally
        {
            IsBusy = false;
        }
    }

    private void RaiseCanSubmit()
    {
        RaisePropertyChanged(nameof(CanSubmit));
    }

    private void SetStatus(string message, bool isError)
    {
        isStatusError = isError;
        StatusMessage = message;
        RaisePropertyChanged(nameof(StatusBrush));
    }

    private static string BuildApiErrorMessage(ApiException exception)
    {
        if (exception.StatusCode == System.Net.HttpStatusCode.UnprocessableEntity && !string.IsNullOrWhiteSpace(exception.ResponseBody))
        {
            string? validation = TryExtractFirstValidationMessage(exception.ResponseBody);
            if (!string.IsNullOrWhiteSpace(validation))
            {
                return validation;
            }
        }

        return exception.Message;
    }

    private static string? TryExtractFirstValidationMessage(string payload)
    {
        try
        {
            using System.Text.Json.JsonDocument document = System.Text.Json.JsonDocument.Parse(payload);
            if (document.RootElement.TryGetProperty("errors", out System.Text.Json.JsonElement errors)
                && errors.ValueKind == System.Text.Json.JsonValueKind.Object)
            {
                foreach (System.Text.Json.JsonProperty property in errors.EnumerateObject())
                {
                    if (property.Value.ValueKind == System.Text.Json.JsonValueKind.Array
                        && property.Value.GetArrayLength() > 0)
                    {
                        string? first = property.Value[0].GetString();
                        if (!string.IsNullOrWhiteSpace(first))
                        {
                            return first;
                        }
                    }
                }
            }

            if (document.RootElement.TryGetProperty("message", out System.Text.Json.JsonElement messageElement))
            {
                string? message = messageElement.GetString();
                if (!string.IsNullOrWhiteSpace(message))
                {
                    return message;
                }
            }
        }
        catch
        {
            // payload no es JSON valido, devolvemos null y caemos al mensaje generico
        }

        return null;
    }
}

public sealed class TransferCreationLine : ViewModelBase
{
    private decimal quantity;

    public TransferCreationLine(TransferProductOption product, decimal quantity)
    {
        Product = product;
        this.quantity = quantity;
    }

    public TransferProductOption Product { get; }

    public long ProductId => Product.Id;

    public string ProductName => Product.Name;

    public string ProductSku => Product.Sku;

    public string TrackingLabel => Product.TrackingLabel;

    public decimal Quantity
    {
        get => quantity;
        set
        {
            if (SetProperty(ref quantity, value < 0 ? 0 : value))
            {
                RaisePropertyChanged(nameof(QuantityLabel));
            }
        }
    }

    public string QuantityLabel => quantity.ToString("0.##");

    public void RaiseQuantityChanged()
    {
        RaisePropertyChanged(nameof(Quantity));
        RaisePropertyChanged(nameof(QuantityLabel));
    }
}
