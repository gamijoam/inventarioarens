using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Globalization;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Media;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductFormWindow : Window, INotifyPropertyChanged
{
    private readonly ApiClient apiClient;
    private readonly long? productId;
    private ProductOption selectedTracking = ProductOption.QuantityTracking;
    private ProductOption selectedCurrency = ProductOption.UsdCurrency;
    private ExchangeRateTypeOption? selectedRateType;
    private WarrantyPolicyOption? selectedWarrantyPolicy;
    private string statusMessage = "Completa los datos del producto.";
    private bool isStatusError;
    private bool isBusy;
    private bool canChangeTrackingType = true;

    public InventoryProductFormWindow(ApiClient apiClient, long? productId = null)
    {
        this.apiClient = apiClient;
        this.productId = productId;

        InitializeComponent();
        DataContext = this;
        Title = productId is null ? "Nuevo producto" : "Editar producto";
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public bool WasSaved { get; private set; }

    public string ModeLabel => productId is null ? "NUEVO" : "EDICIÓN";

    public string TitleLabel => productId is null ? "Nuevo producto" : "Editar producto";

    public string SaveButtonLabel => productId is null ? "Crear producto" : "Guardar cambios";

    public string TrackingHelpText => canChangeTrackingType
        ? "El tipo de control define si el stock se maneja por cantidad o por IMEI/serial."
        : "Este producto ya tiene unidades serializadas; Laravel no permite cambiar el tipo de control.";

    public ObservableCollection<ExchangeRateTypeOption> RateTypes { get; } = new();

    public ObservableCollection<WarrantyPolicyOption> WarrantyPolicies { get; } = new();

    public IReadOnlyList<ProductOption> TrackingOptions { get; } =
    [
        ProductOption.QuantityTracking,
        ProductOption.SerializedTracking,
    ];

    public IReadOnlyList<ProductOption> CurrencyOptions { get; } =
    [
        ProductOption.UsdCurrency,
        ProductOption.VesCurrency,
    ];

    public ProductOption SelectedTracking
    {
        get => selectedTracking;
        set => SetProperty(ref selectedTracking, value);
    }

    public ProductOption SelectedCurrency
    {
        get => selectedCurrency;
        set => SetProperty(ref selectedCurrency, value);
    }

    public ExchangeRateTypeOption? SelectedRateType
    {
        get => selectedRateType;
        set => SetProperty(ref selectedRateType, value);
    }

    public WarrantyPolicyOption? SelectedWarrantyPolicy
    {
        get => selectedWarrantyPolicy;
        set => SetProperty(ref selectedWarrantyPolicy, value);
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

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

    public async Task InitializeAsync()
    {
        await LoadOptionsAsync();

        if (productId is not null)
        {
            await LoadProductAsync(productId.Value);
        }
    }

    private async void Save_Click(object sender, RoutedEventArgs e)
    {
        if (isBusy)
        {
            return;
        }

        if (!TryBuildRequest(out ProductSaveRequest? request))
        {
            return;
        }

        isBusy = true;
        IsStatusError = false;
        StatusMessage = productId is null ? "Creando producto..." : "Guardando producto...";

        try
        {
            if (productId is null)
            {
                await apiClient.PostAsync<ProductSaveRequest, ProductApiResponse>("products", request!);
            }
            else
            {
                await apiClient.PatchAsync<ProductSaveRequest, ProductApiResponse>($"products/{productId.Value}", request!);
            }

            WasSaved = true;
            StatusMessage = productId is null ? "Producto creado correctamente." : "Producto actualizado correctamente.";
            Close();
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo conectar con la API para guardar el producto.";
            IsStatusError = true;
        }
        catch (TaskCanceledException)
        {
            StatusMessage = "La operación tardó demasiado. Intenta nuevamente.";
            IsStatusError = true;
        }
        finally
        {
            isBusy = false;
        }
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private async Task LoadOptionsAsync()
    {
        try
        {
            ExchangeRateTypeListResponse rateResponse = await apiClient.GetAsync<ExchangeRateTypeListResponse>("currency/rate-types");
            RateTypes.Clear();
            foreach (ExchangeRateTypeOption rateType in rateResponse.Data.Where(rateType => rateType.IsActive))
            {
                RateTypes.Add(rateType);
            }

            SelectedRateType = RateTypes.FirstOrDefault(rateType => rateType.IsDefault) ?? RateTypes.FirstOrDefault();
        }
        catch
        {
            StatusMessage = "No se pudieron cargar las tasas. Puedes guardar sin tasa o intentar de nuevo.";
            IsStatusError = false;
        }

        try
        {
            WarrantyPolicyListResponse warrantyResponse = await apiClient.GetAsync<WarrantyPolicyListResponse>("warranty-policies");
            WarrantyPolicies.Clear();
            foreach (WarrantyPolicyOption warranty in warrantyResponse.Data.Where(warranty => warranty.IsActive))
            {
                WarrantyPolicies.Add(warranty);
            }
        }
        catch
        {
            if (!IsStatusError)
            {
                StatusMessage = "No se pudieron cargar las garantías. Puedes guardar sin garantía o intentar de nuevo.";
            }
        }
    }

    private async Task LoadProductAsync(long id)
    {
        try
        {
            ProductApiResponse response = await apiClient.GetAsync<ProductApiResponse>($"products/{id}");
            ProductApiData product = response.Data;

            NameBox.Text = product.Name;
            SkuBox.Text = product.Sku;
            BasePriceBox.Text = product.BasePrice?.ToString("0.##", CultureInfo.CurrentCulture) ?? "";
            ActiveBox.IsChecked = product.IsActive;
            SelectedTracking = TrackingOptions.FirstOrDefault(option => option.Value == product.TrackingType) ?? ProductOption.QuantityTracking;
            SelectedCurrency = CurrencyOptions.FirstOrDefault(option => option.Value == product.SaleCurrency) ?? ProductOption.UsdCurrency;
            SelectedRateType = RateTypes.FirstOrDefault(option => option.Id == product.SaleExchangeRateTypeId);
            SelectedWarrantyPolicy = WarrantyPolicies.FirstOrDefault(option => option.Id == product.WarrantyPolicyId);
            canChangeTrackingType = product.CanChangeTrackingType ?? true;
            TrackingBox.IsEnabled = canChangeTrackingType;
            RaisePropertyChanged(nameof(TrackingHelpText));
        }
        catch (ApiException exception)
        {
            StatusMessage = exception.Message;
            IsStatusError = true;
        }
        catch (HttpRequestException)
        {
            StatusMessage = "No se pudo cargar el producto desde la API.";
            IsStatusError = true;
        }
    }

    private bool TryBuildRequest(out ProductSaveRequest? request)
    {
        request = null;
        string name = NameBox.Text.Trim();
        string sku = SkuBox.Text.Trim();

        if (string.IsNullOrWhiteSpace(name))
        {
            return Fail("El nombre del producto es obligatorio.");
        }

        decimal? basePrice = null;
        if (!string.IsNullOrWhiteSpace(BasePriceBox.Text))
        {
            if (!TryReadDecimal(BasePriceBox.Text, out decimal parsedPrice) || parsedPrice < 0)
            {
                return Fail("El precio base no es válido.");
            }

            basePrice = parsedPrice;
        }

        request = new ProductSaveRequest(
            name,
            sku,
            SelectedTracking.Value,
            basePrice,
            SelectedCurrency.Value,
            SelectedRateType?.Id,
            SelectedWarrantyPolicy?.Id,
            ActiveBox.IsChecked == true);

        return true;
    }

    private bool Fail(string message)
    {
        StatusMessage = message;
        IsStatusError = true;
        return false;
    }

    private static bool TryReadDecimal(string value, out decimal result)
    {
        return decimal.TryParse(value, NumberStyles.Number, CultureInfo.CurrentCulture, out result)
            || decimal.TryParse(value, NumberStyles.Number, CultureInfo.InvariantCulture, out result);
    }

    private bool SetProperty<T>(ref T field, T value, [CallerMemberName] string? propertyName = null)
    {
        if (EqualityComparer<T>.Default.Equals(field, value))
        {
            return false;
        }

        field = value;
        RaisePropertyChanged(propertyName);
        return true;
    }

    private void RaisePropertyChanged([CallerMemberName] string? propertyName = null)
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
    }
}

public sealed record ProductOption(string Value, string Label)
{
    public static ProductOption QuantityTracking { get; } = new("quantity", "Por cantidad");

    public static ProductOption SerializedTracking { get; } = new("serialized", "Serializado / IMEI");

    public static ProductOption UsdCurrency { get; } = new("USD", "Dólares (USD)");

    public static ProductOption VesCurrency { get; } = new("VES", "Bolívares (VES)");
}
