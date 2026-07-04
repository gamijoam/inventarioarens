using System.Collections.ObjectModel;
using System.Net.Http;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryBulkActionWindow : Window
{
    private readonly InventoryBulkActionWindowModel model;

    public InventoryBulkActionWindow(ApiClient apiClient, IReadOnlyList<InventoryProductItem> products)
    {
        InitializeComponent();
        model = new InventoryBulkActionWindowModel(apiClient, products);
        DataContext = model;
    }

    public event EventHandler? Completed;

    public async Task InitializeAsync()
    {
        await model.LoadOptionsAsync();
        UpdatePanels();
    }

    private void Action_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        UpdatePanels();
        model.RefreshSubmitState();
    }

    private void UpdatePanels()
    {
        WarrantyPanel.Visibility = model.SelectedAction?.Value == InventoryBulkActionWindowModel.AssignWarrantyPolicy
            ? Visibility.Visible
            : Visibility.Collapsed;
        RatePanel.Visibility = model.SelectedAction?.Value == InventoryBulkActionWindowModel.AssignExchangeRateType
            ? Visibility.Visible
            : Visibility.Collapsed;
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private async void Submit_Click(object sender, RoutedEventArgs e)
    {
        bool completed = await model.SubmitAsync();
        if (!completed)
        {
            return;
        }

        Completed?.Invoke(this, EventArgs.Empty);
        Close();
    }
}

public sealed class InventoryBulkActionWindowModel : ViewModelBase
{
    public const string Activate = "activate";
    public const string Deactivate = "deactivate";
    public const string AssignWarrantyPolicy = "assign_warranty_policy";
    public const string AssignExchangeRateType = "assign_exchange_rate_type";

    private readonly ApiClient apiClient;
    private BulkActionOption? selectedAction;
    private WarrantyPolicyOption? selectedWarrantyPolicy;
    private ExchangeRateTypeOption? selectedExchangeRateType;
    private string statusMessage = "Selecciona una acción para continuar.";
    private bool isStatusError;
    private bool isBusy;

    public InventoryBulkActionWindowModel(ApiClient apiClient, IReadOnlyList<InventoryProductItem> products)
    {
        this.apiClient = apiClient;
        Products = products;
        Actions =
        [
            new(Activate, "Activar productos"),
            new(Deactivate, "Desactivar productos"),
            new(AssignWarrantyPolicy, "Asignar garantía"),
            new(AssignExchangeRateType, "Asignar tipo de tasa"),
        ];
    }

    public IReadOnlyList<InventoryProductItem> Products { get; }

    public IReadOnlyList<BulkActionOption> Actions { get; }

    public ObservableCollection<WarrantyPolicyOption> WarrantyPolicies { get; } = new();

    public ObservableCollection<ExchangeRateTypeOption> ExchangeRateTypes { get; } = new();

    public string SummaryLabel => Products.Count == 1
        ? "Aplicarás una acción a 1 producto seleccionado."
        : $"Aplicarás una acción a {Products.Count} productos seleccionados.";

    public BulkActionOption? SelectedAction
    {
        get => selectedAction;
        set
        {
            if (SetProperty(ref selectedAction, value))
            {
                StatusMessage = value is null ? "Selecciona una acción para continuar." : $"Acción preparada: {value.Label}.";
                IsStatusError = false;
                RaisePropertyChanged(nameof(WarningLabel));
                RefreshSubmitState();
            }
        }
    }

    public WarrantyPolicyOption? SelectedWarrantyPolicy
    {
        get => selectedWarrantyPolicy;
        set
        {
            if (SetProperty(ref selectedWarrantyPolicy, value))
            {
                RefreshSubmitState();
            }
        }
    }

    public ExchangeRateTypeOption? SelectedExchangeRateType
    {
        get => selectedExchangeRateType;
        set
        {
            if (SetProperty(ref selectedExchangeRateType, value))
            {
                RefreshSubmitState();
            }
        }
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
                RefreshSubmitState();
            }
        }
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public string WarningLabel => SelectedAction?.Value switch
    {
        Activate => "Los productos seleccionados quedarán disponibles para operación y venta si cumplen el resto de reglas.",
        Deactivate => "Los productos seleccionados dejarán de aparecer como activos. No se elimina historial ni movimientos.",
        AssignWarrantyPolicy => "La garantía seleccionada se aplicará a todos los productos de la selección.",
        AssignExchangeRateType => "El tipo de tasa seleccionado se usará como tasa de venta de los productos elegidos.",
        _ => "Esta herramienta modifica varios productos y deja auditoría por cada cambio aplicado.",
    };

    public bool CanSubmit => !IsBusy
        && SelectedAction is not null
        && Products.Count > 0
        && (SelectedAction.Value != AssignWarrantyPolicy || SelectedWarrantyPolicy is not null)
        && (SelectedAction.Value != AssignExchangeRateType || SelectedExchangeRateType is not null);

    public async Task LoadOptionsAsync()
    {
        try
        {
            IsBusy = true;
            StatusMessage = "Cargando opciones...";
            IsStatusError = false;

            WarrantyPolicies.Clear();
            WarrantyPolicyListResponse warrantyResponse = await apiClient.GetAsync<WarrantyPolicyListResponse>("warranty-policies");
            foreach (WarrantyPolicyOption option in warrantyResponse.Data.Where(option => option.IsActive))
            {
                WarrantyPolicies.Add(option);
            }

            ExchangeRateTypes.Clear();
            ExchangeRateTypeListResponse rateResponse = await apiClient.GetAsync<ExchangeRateTypeListResponse>("currency/rate-types");
            foreach (ExchangeRateTypeOption option in rateResponse.Data.Where(option => option.IsActive))
            {
                ExchangeRateTypes.Add(option);
            }

            StatusMessage = "Opciones cargadas. Selecciona una acción.";
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API para cargar las opciones.";
            IsStatusError = true;
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task<bool> SubmitAsync()
    {
        if (!CanSubmit || SelectedAction is null)
        {
            StatusMessage = "Completa los datos requeridos antes de aplicar la acción.";
            IsStatusError = true;
            return false;
        }

        try
        {
            IsBusy = true;
            StatusMessage = "Aplicando acción masiva...";
            IsStatusError = false;

            InventoryBulkActionPayload? payload = BuildPayload();
            InventoryBulkActionRequest request = new(
                Products.Select(product => product.Id).ToList(),
                SelectedAction.Value,
                payload);

            InventoryBulkActionResponse response = await apiClient.PostAsync<InventoryBulkActionRequest, InventoryBulkActionResponse>(
                "inventory-center/products/bulk-action",
                request);

            StatusMessage = $"Acción aplicada. Actualizados: {response.Data.UpdatedCount}. Sin cambios: {response.Data.SkippedCount}.";
            IsStatusError = false;
            return true;
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API para aplicar la acción.";
            IsStatusError = true;
        }
        finally
        {
            IsBusy = false;
        }

        return false;
    }

    public void RefreshSubmitState()
    {
        RaisePropertyChanged(nameof(CanSubmit));
    }

    private InventoryBulkActionPayload? BuildPayload()
    {
        return SelectedAction?.Value switch
        {
            AssignWarrantyPolicy => new InventoryBulkActionPayload(WarrantyPolicyId: SelectedWarrantyPolicy?.Id),
            AssignExchangeRateType => new InventoryBulkActionPayload(SaleExchangeRateTypeId: SelectedExchangeRateType?.Id),
            _ => null,
        };
    }
}

public sealed record BulkActionOption(string Value, string Label);
