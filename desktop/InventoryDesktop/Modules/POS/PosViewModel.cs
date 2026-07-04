using System.Collections.ObjectModel;
using System.Globalization;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public sealed class PosViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private string searchText = "";
    private string statusMessage = "Busca un producto por nombre, SKU o serial/IMEI.";
    private bool isStatusError;
    private bool isBusy;
    private PriceListOption? selectedPriceList;

    public PosViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
    }

    public ObservableCollection<PriceListOption> PriceLists { get; } = new();

    public ObservableCollection<PosProductCard> Products { get; } = new();

    public ObservableCollection<PosCartItem> CartItems { get; } = new();

    public string SearchText
    {
        get => searchText;
        set => SetProperty(ref searchText, value);
    }

    public PriceListOption? SelectedPriceList
    {
        get => selectedPriceList;
        set
        {
            if (SetProperty(ref selectedPriceList, value))
            {
                RaisePropertyChanged(nameof(PriceListLabel));
            }
        }
    }

    public string PriceListLabel => SelectedPriceList is null
        ? "Lista: predeterminada"
        : $"Lista: {SelectedPriceList.Name}";

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
        set => SetProperty(ref isBusy, value);
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public decimal TotalUsd => CartItems.Sum(item => item.TotalUsd);

    public decimal? TotalVes => CartItems.Any(item => item.TotalVes is null)
        ? null
        : CartItems.Sum(item => item.TotalVes ?? 0m);

    public string TotalUsdLabel => $"USD {TotalUsd:0.00}";

    public string TotalVesLabel => TotalVes is null ? "Bs por calcular" : $"Bs {TotalVes:0.00}";

    public string CartCountLabel => CartItems.Count == 0
        ? "Sin productos"
        : $"{CartItems.Sum(item => item.Quantity):0.##} unidades";

    public async Task InitializeAsync()
    {
        await LoadPriceListsAsync();
        await SearchAsync();
    }

    public async Task LoadPriceListsAsync()
    {
        try
        {
            PriceListListResponse response = await apiClient.GetAsync<PriceListListResponse>("price-lists?active_only=1");
            PriceLists.Clear();
            foreach (PriceListOption priceList in response.Data.Where(list => list.IsActive))
            {
                PriceLists.Add(priceList);
            }

            SelectedPriceList = PriceLists.FirstOrDefault(list => list.IsDefault) ?? PriceLists.FirstOrDefault();
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar listas de precio.");
        }
    }

    public async Task SearchAsync()
    {
        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Buscando productos...";

            string query = BuildQuery([
                ("search", SearchText),
                ("stock_status", "all"),
                ("limit", "24"),
            ]);
            InventoryCenterSummaryResponse response = await apiClient.GetAsync<InventoryCenterSummaryResponse>($"inventory-center/summary{query}");

            Products.Clear();
            foreach (InventoryProductItem product in response.Data.Products)
            {
                Products.Add(new PosProductCard(product));
            }

            StatusMessage = Products.Count == 0
                ? "No se encontraron productos para la búsqueda."
                : $"{Products.Count} productos disponibles para seleccionar.";
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para buscar productos.");
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task AddProductAsync(PosProductCard card)
    {
        if (card.Product.Stock.Available <= 0)
        {
            SetError("No se puede agregar un producto sin stock disponible.");
            return;
        }

        if (card.Product.TrackingType == "serialized")
        {
            SetError("Este producto requiere seleccionar IMEI/serial. Esa selección se integrará en la siguiente fase del POS.");
            return;
        }

        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Cotizando producto...";

            string priceQuery = SelectedPriceList is null ? "" : $"?price_list_id={SelectedPriceList.Id}";
            PosPriceQuoteResponse response = await apiClient.GetAsync<PosPriceQuoteResponse>($"products/{card.Product.Id}/price{priceQuery}");
            PosPriceQuote quote = response.Data;

            PosCartItem? existing = CartItems.FirstOrDefault(item =>
                item.ProductId == card.Product.Id
                && item.PriceListId == quote.PriceListId
                && item.UnitPriceUsd == quote.PriceUsd);

            if (existing is not null)
            {
                existing.Increase();
            }
            else
            {
                PosCartItem item = new(card.Product, quote);
                item.Changed += (_, _) => RaiseTotalsChanged();
                CartItems.Add(item);
            }

            RaiseTotalsChanged();
            StatusMessage = $"{card.Product.Name} agregado al carrito.";
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cotizar el producto.");
        }
        finally
        {
            IsBusy = false;
        }
    }

    public void RemoveItem(PosCartItem item)
    {
        CartItems.Remove(item);
        RaiseTotalsChanged();
        StatusMessage = "Producto retirado del carrito.";
        IsStatusError = false;
    }

    public void ClearCart()
    {
        CartItems.Clear();
        RaiseTotalsChanged();
        StatusMessage = "Carrito limpiado.";
        IsStatusError = false;
    }

    public void Increase(PosCartItem item)
    {
        item.Increase();
        RaiseTotalsChanged();
    }

    public void Decrease(PosCartItem item)
    {
        item.Decrease();
        if (item.Quantity <= 0)
        {
            CartItems.Remove(item);
        }

        RaiseTotalsChanged();
    }

    private void RaiseTotalsChanged()
    {
        RaisePropertyChanged(nameof(TotalUsd));
        RaisePropertyChanged(nameof(TotalVes));
        RaisePropertyChanged(nameof(TotalUsdLabel));
        RaisePropertyChanged(nameof(TotalVesLabel));
        RaisePropertyChanged(nameof(CartCountLabel));
    }

    private void SetError(string message)
    {
        IsStatusError = true;
        StatusMessage = message;
    }

    private static string BuildQuery(IEnumerable<(string Key, string? Value)> values)
    {
        List<string> parts = values
            .Where(value => !string.IsNullOrWhiteSpace(value.Value))
            .Select(value => $"{Uri.EscapeDataString(value.Key)}={Uri.EscapeDataString(value.Value!.Trim())}")
            .ToList();

        return parts.Count == 0 ? string.Empty : "?" + string.Join("&", parts);
    }
}

public sealed class PosProductCard(InventoryProductItem product)
{
    public InventoryProductItem Product { get; } = product;

    public string Initials
    {
        get
        {
            string[] parts = Product.Name.Split(' ', StringSplitOptions.RemoveEmptyEntries);
            string initials = string.Concat(parts.Take(2).Select(part => part[0])).ToUpperInvariant();
            return initials.Length == 0 ? "PR" : initials;
        }
    }

    public string StockLabel => Product.Stock.Available <= 0
        ? "Sin stock"
        : $"{Product.Stock.Available:0.##} disp.";

    public string CardPriceLabel => Product.BasePrice is null ? "Sin precio base" : Product.PriceLabel;

    public string TrackingShortLabel => Product.TrackingType == "serialized" ? "IMEI" : "Cantidad";
}

public sealed class PosCartItem : ViewModelBase
{
    private decimal quantity = 1;

    public PosCartItem(InventoryProductItem product, PosPriceQuote quote)
    {
        ProductId = product.Id;
        Name = product.Name;
        Sku = product.Sku;
        PriceListId = quote.PriceListId;
        PriceListLabel = quote.PriceListLabel;
        UnitPriceUsd = quote.PriceUsd;
        UnitPriceVes = quote.PriceVes;
        SaleCurrency = quote.SaleCurrency;
        SalePrice = quote.SalePrice;
        RateLabel = quote.RateLabel;
    }

    public event EventHandler? Changed;

    public long ProductId { get; }

    public string Name { get; }

    public string Sku { get; }

    public long? PriceListId { get; }

    public string PriceListLabel { get; }

    public decimal UnitPriceUsd { get; }

    public decimal? UnitPriceVes { get; }

    public string SaleCurrency { get; }

    public decimal SalePrice { get; }

    public string RateLabel { get; }

    public decimal Quantity
    {
        get => quantity;
        private set
        {
            if (SetProperty(ref quantity, value))
            {
                RaisePropertyChanged(nameof(QuantityLabel));
                RaisePropertyChanged(nameof(TotalUsd));
                RaisePropertyChanged(nameof(TotalVes));
                RaisePropertyChanged(nameof(TotalLabel));
                Changed?.Invoke(this, EventArgs.Empty);
            }
        }
    }

    public string QuantityLabel => Quantity.ToString("0.##", CultureInfo.CurrentCulture);

    public decimal TotalUsd => UnitPriceUsd * Quantity;

    public decimal? TotalVes => UnitPriceVes is null ? null : UnitPriceVes.Value * Quantity;

    public string UnitPriceLabel => $"{SaleCurrency} {SalePrice:0.00}";

    public string TotalLabel => $"USD {TotalUsd:0.00}";

    public void Increase()
    {
        Quantity += 1;
    }

    public void Decrease()
    {
        Quantity -= 1;
    }
}
