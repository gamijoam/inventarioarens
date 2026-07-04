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
        PriceListPanel.Visibility = model.SelectedAction?.Value == InventoryBulkActionWindowModel.FillMissingPriceList
            ? Visibility.Visible
            : Visibility.Collapsed;
        UpdatePriceStrategyPanels();
    }

    private void PriceStrategy_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        UpdatePriceStrategyPanels();
        model.RefreshSubmitState();
    }

    private void UpdatePriceStrategyPanels()
    {
        FixedPricePanel.Visibility = model.SelectedPriceStrategy?.Value == InventoryBulkActionWindowModel.PriceStrategyFixedPrice
            ? Visibility.Visible
            : Visibility.Collapsed;
        PercentPanel.Visibility = model.SelectedPriceStrategy?.Value == InventoryBulkActionWindowModel.PriceStrategyPercentOverBase
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
    public const string FillMissingPriceList = "fill_missing_price_list";

    public const string PriceStrategyBasePrice = "base_price";
    public const string PriceStrategyFixedPrice = "fixed_price";
    public const string PriceStrategyPercentOverBase = "percent_over_base";

    private readonly ApiClient apiClient;
    private BulkActionOption? selectedAction;
    private WarrantyPolicyOption? selectedWarrantyPolicy;
    private ExchangeRateTypeOption? selectedExchangeRateType;
    private PriceListOption? selectedPriceList;
    private PriceStrategyOption? selectedPriceStrategy;
    private string selectedCurrency = "USD";
    private string fixedPriceText = "";
    private string percentText = "";
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
            new(FillMissingPriceList, "Completar precios por lista"),
        ];
        PriceStrategies =
        [
            new(PriceStrategyBasePrice, "Copiar precio base"),
            new(PriceStrategyFixedPrice, "Aplicar monto fijo"),
            new(PriceStrategyPercentOverBase, "Aplicar porcentaje sobre base"),
        ];
    }

    public IReadOnlyList<InventoryProductItem> Products { get; }

    public IReadOnlyList<BulkActionOption> Actions { get; }

    public IReadOnlyList<PriceStrategyOption> PriceStrategies { get; }

    public IReadOnlyList<string> CurrencyOptions { get; } = ["USD", "VES"];

    public ObservableCollection<WarrantyPolicyOption> WarrantyPolicies { get; } = new();

    public ObservableCollection<ExchangeRateTypeOption> ExchangeRateTypes { get; } = new();

    public ObservableCollection<PriceListOption> PriceLists { get; } = new();

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

    public PriceListOption? SelectedPriceList
    {
        get => selectedPriceList;
        set
        {
            if (SetProperty(ref selectedPriceList, value))
            {
                RefreshSubmitState();
            }
        }
    }

    public PriceStrategyOption? SelectedPriceStrategy
    {
        get => selectedPriceStrategy;
        set
        {
            if (SetProperty(ref selectedPriceStrategy, value))
            {
                RaisePropertyChanged(nameof(WarningLabel));
                RefreshSubmitState();
            }
        }
    }

    public string SelectedCurrency
    {
        get => selectedCurrency;
        set
        {
            if (SetProperty(ref selectedCurrency, value))
            {
                RefreshSubmitState();
            }
        }
    }

    public string FixedPriceText
    {
        get => fixedPriceText;
        set
        {
            if (SetProperty(ref fixedPriceText, value))
            {
                RefreshSubmitState();
            }
        }
    }

    public string PercentText
    {
        get => percentText;
        set
        {
            if (SetProperty(ref percentText, value))
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
        FillMissingPriceList => "Solo se crearán precios faltantes. Los productos que ya tengan precio en esa lista no se sobrescriben.",
        _ => "Esta herramienta modifica varios productos y deja auditoría por cada cambio aplicado.",
    };

    public bool CanSubmit => !IsBusy
        && SelectedAction is not null
        && Products.Count > 0
        && (SelectedAction.Value != AssignWarrantyPolicy || SelectedWarrantyPolicy is not null)
        && (SelectedAction.Value != AssignExchangeRateType || SelectedExchangeRateType is not null)
        && (SelectedAction.Value != FillMissingPriceList || CanSubmitPriceListAction());

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

            PriceLists.Clear();
            PriceListListResponse priceListResponse = await apiClient.GetAsync<PriceListListResponse>("price-lists");
            foreach (PriceListOption option in priceListResponse.Data.Where(option => option.IsActive))
            {
                PriceLists.Add(option);
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
            FillMissingPriceList => new InventoryBulkActionPayload(
                PriceListId: SelectedPriceList?.Id,
                Strategy: SelectedPriceStrategy?.Value,
                Price: TryParseDecimal(FixedPriceText),
                Percent: TryParseDecimal(PercentText),
                Currency: SelectedCurrency),
            _ => null,
        };
    }

    private bool CanSubmitPriceListAction()
    {
        if (SelectedPriceList is null || SelectedPriceStrategy is null || string.IsNullOrWhiteSpace(SelectedCurrency))
        {
            return false;
        }

        if (SelectedPriceStrategy.Value == PriceStrategyFixedPrice)
        {
            return TryParseDecimal(FixedPriceText) is >= 0;
        }

        if (SelectedPriceStrategy.Value == PriceStrategyPercentOverBase)
        {
            decimal? percent = TryParseDecimal(PercentText);
            return percent is >= -99 and <= 10000;
        }

        return true;
    }

    private static decimal? TryParseDecimal(string value)
    {
        if (decimal.TryParse(value, System.Globalization.NumberStyles.Number, System.Globalization.CultureInfo.CurrentCulture, out decimal parsed))
        {
            return parsed;
        }

        if (decimal.TryParse(value, System.Globalization.NumberStyles.Number, System.Globalization.CultureInfo.InvariantCulture, out parsed))
        {
            return parsed;
        }

        return null;
    }
}

public sealed record BulkActionOption(string Value, string Label);

public sealed record PriceStrategyOption(string Value, string Label);
