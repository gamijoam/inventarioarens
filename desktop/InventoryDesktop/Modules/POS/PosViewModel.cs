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
    private readonly Dictionary<QuoteCacheKey, PosPriceQuote> quoteCache = new();
    private readonly Dictionary<QuoteCacheKey, Task<PosPriceQuote>> quoteRequests = new();
    private readonly object quoteSync = new();
    private string searchText = "";
    private string statusMessage = "Busca un producto por nombre, SKU o serial/IMEI.";
    private bool isStatusError;
    private bool isBusy;
    private PriceListOption? selectedPriceList;
    private InventoryWarehouseOption? selectedWarehouse;
    private PosCashRegisterSession? selectedCashRegisterSession;
    private PosCustomerOption? selectedCustomer;
    private CancellationTokenSource? quoteWarmupCancellation;

    private readonly long currentUserId;

    public PosViewModel(ApiClient apiClient, long currentUserId)
    {
        this.apiClient = apiClient;
        this.currentUserId = currentUserId;
    }

    public ObservableCollection<PriceListOption> PriceLists { get; } = new();

    public ObservableCollection<PosProductCard> Products { get; } = new();

    public ObservableCollection<PosCartItem> CartItems { get; } = new();

    public ObservableCollection<InventoryWarehouseOption> Warehouses { get; } = new();

    public ObservableCollection<PosCashRegisterSession> CashRegisterSessions { get; } = new();

    public ObservableCollection<PaymentMethodOption> PaymentMethods { get; } = new();

    public ObservableCollection<PosCustomerOption> CustomerSearchResults { get; } = new();

    public ObservableCollection<PosOrderSummary> PendingOrders { get; } = new();

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
                ResetQuoteCache();
                RaisePropertyChanged(nameof(PriceListLabel));
                StartQuoteWarmup();
            }
        }
    }

    public string PriceListLabel => SelectedPriceList is null
        ? "Lista: predeterminada"
        : $"Lista: {SelectedPriceList.Name}";

    public InventoryWarehouseOption? SelectedWarehouse
    {
        get => selectedWarehouse;
        set
        {
            if (SetProperty(ref selectedWarehouse, value))
            {
                RaisePropertyChanged(nameof(OperationalContextLabel));
            }
        }
    }

    public PosCashRegisterSession? SelectedCashRegisterSession
    {
        get => selectedCashRegisterSession;
        set
        {
            if (SetProperty(ref selectedCashRegisterSession, value))
            {
                RaisePropertyChanged(nameof(OperationalContextLabel));
                RaisePropertyChanged(nameof(CanPay));
                RaisePropertyChanged(nameof(PayHint));
            }
        }
    }

    public string OperationalContextLabel
    {
        get
        {
            string warehouse = SelectedWarehouse?.WarehouseLabel ?? "Sin almacén";
            string cashRegister = SelectedCashRegisterSession?.DisplayLabel ?? "Sin caja abierta";
            return $"{warehouse} · {cashRegister}";
        }
    }

    public PosCustomerOption? SelectedCustomer
    {
        get => selectedCustomer;
        set
        {
            if (SetProperty(ref selectedCustomer, value))
            {
                RaisePropertyChanged(nameof(CustomerLabel));
                RaisePropertyChanged(nameof(CustomerDetailLabel));
            }
        }
    }

    public string CustomerLabel => SelectedCustomer?.Name ?? "Cliente mostrador";

    public string CustomerDetailLabel => SelectedCustomer?.DetailLabel ?? "Venta rápida sin cliente registrado";

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
                RaisePropertyChanged(nameof(CanPay));
                RaisePropertyChanged(nameof(PayHint));
            }
        }
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

    public bool CanPay => CartItems.Count > 0 && !IsBusy;

    public string PayHint
    {
        get
        {
            if (CartItems.Count == 0)
            {
                return "Agrega productos para poder cobrar.";
            }

            if (SelectedCashRegisterSession is null)
            {
                return "No tienes una caja abierta asignada a tu usuario. Recarga contexto o abre tu caja.";
            }

            return "Abre la ventana de cobro para pagos en USD, Bs o mixtos.";
        }
    }

    public async Task InitializeAsync()
    {
        await LoadPriceListsAsync();
        await LoadPaymentMethodsAsync();
        await LoadOperationalContextAsync();
        await SearchAsync();
    }

    public async Task LoadOperationalContextAsync()
    {
        await LoadWarehousesAsync();
        await LoadCashRegisterSessionsAsync();
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

    public async Task LoadWarehousesAsync()
    {
        try
        {
            WarehouseListResponse response = await apiClient.GetAsync<WarehouseListResponse>("warehouses");
            long? selectedId = SelectedWarehouse?.Id;
            List<InventoryWarehouseOption> activeWarehouses = response.Data
                .Where(warehouse => warehouse.Status is null || warehouse.Status == "active")
                .ToList();

            Warehouses.Clear();
            foreach (InventoryWarehouseOption warehouse in activeWarehouses)
            {
                Warehouses.Add(warehouse);
            }

            SelectedWarehouse = Warehouses.FirstOrDefault(warehouse => warehouse.Id == selectedId) ?? Warehouses.FirstOrDefault();
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar almacenes.");
        }
    }

    public async Task LoadCashRegisterSessionsAsync()
    {
        try
        {
            PosCashRegisterSessionListResponse response = await apiClient.GetAsync<PosCashRegisterSessionListResponse>("cash-register/sessions");
            long? selectedId = SelectedCashRegisterSession?.Id;
            List<PosCashRegisterSession> openSessions = response.Data
                .Where(session => session.Status == "open" && session.CashierId == currentUserId)
                .ToList();

            CashRegisterSessions.Clear();
            foreach (PosCashRegisterSession session in openSessions)
            {
                CashRegisterSessions.Add(session);
            }

            SelectedCashRegisterSession = CashRegisterSessions.FirstOrDefault(session => session.Id == selectedId)
                ?? CashRegisterSessions.FirstOrDefault();

            if (SelectedCashRegisterSession is null)
            {
                SetError("No tienes una caja abierta asignada a tu usuario. Abre tu caja antes de vender.");
            }
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar cajas abiertas.");
        }
    }

    public async Task OpenOwnCashRegisterAsync()
    {
        if (SelectedWarehouse?.BranchId is not long branchId)
        {
            SetError("Selecciona un almacén con sucursal para abrir caja.");
            return;
        }

        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Abriendo caja para tu usuario...";

            PosCashRegisterSessionResponse response = await apiClient.PostAsync<PosOpenCashRegisterRequest, PosCashRegisterSessionResponse>(
                "cash-register/sessions",
                new PosOpenCashRegisterRequest(branchId, "USD", 0m, "Apertura desde POS de escritorio."));

            CashRegisterSessions.Clear();
            if (response.Data.Status == "open" && response.Data.CashierId == currentUserId)
            {
                CashRegisterSessions.Add(response.Data);
                SelectedCashRegisterSession = response.Data;
                StatusMessage = $"Caja #{response.Data.Id} abierta para vender.";
                IsStatusError = false;
            }

            await LoadCashRegisterSessionsAsync();
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
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

    public async Task LoadPaymentMethodsAsync()
    {
        try
        {
            PaymentMethodListResponse response = await apiClient.GetAsync<PaymentMethodListResponse>("payment-methods?active_only=1");
            PaymentMethods.Clear();
            foreach (PaymentMethodOption method in response.Data.Where(method => method.IsActive).OrderBy(method => method.SortOrder).ThenBy(method => method.Name))
            {
                PaymentMethods.Add(method);
            }
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar métodos de pago.");
        }
    }

    public async Task<IReadOnlyList<PosCustomerOption>> SearchCustomersAsync(string search)
    {
        try
        {
            string query = BuildQuery([
                ("search", search),
                ("active_only", "1"),
                ("limit", "20"),
            ]);
            PosCustomerListResponse response = await apiClient.GetAsync<PosCustomerListResponse>($"customers{query}");
            CustomerSearchResults.Clear();
            foreach (PosCustomerOption customer in response.Data.Where(customer => customer.IsActive))
            {
                CustomerSearchResults.Add(customer);
            }

            return CustomerSearchResults;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
            return [];
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para buscar clientes.");
            return [];
        }
    }

    public async Task<PosCustomerOption> CreateCustomerAsync(PosCustomerCreateRequest request)
    {
        try
        {
            PosCustomerResponse response = await apiClient.PostAsync<PosCustomerCreateRequest, PosCustomerResponse>("customers", request);
            PosCustomerOption customer = response.Data;
            CustomerSearchResults.Insert(0, customer);
            StatusMessage = $"Cliente {customer.Name} registrado y seleccionado.";
            IsStatusError = false;
            return customer;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
            throw new InvalidOperationException(exception.Message, exception);
        }
        catch (HttpRequestException exception)
        {
            const string message = "No se pudo conectar con la API para registrar el cliente.";
            SetError(message);
            throw new InvalidOperationException(message, exception);
        }
    }

    public async Task<IReadOnlyList<PosOrderSummary>> LoadPendingOrdersAsync()
    {
        try
        {
            PosOrderListResponse response = await apiClient.GetAsync<PosOrderListResponse>("pos/orders?status=open");
            PendingOrders.Clear();
            foreach (PosOrderSummary order in response.Data.OrderByDescending(order => order.Id))
            {
                PendingOrders.Add(order);
            }

            StatusMessage = PendingOrders.Count == 0
                ? "No hay ordenes POS pendientes."
                : $"{PendingOrders.Count} orden(es) POS pendiente(s).";
            IsStatusError = false;
            return PendingOrders;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
            return [];
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar ordenes pendientes.");
            return [];
        }
    }

    public async Task<PosOrderResult> AddPaymentsToPendingOrderAsync(long orderId, IReadOnlyList<PosCheckoutPaymentRequest> payments)
    {
        if (payments.Count == 0)
        {
            throw new InvalidOperationException("Agrega al menos un pago para completar la orden.");
        }

        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Completando cobro de la orden pendiente...";

            PosOrderResponse response = await apiClient.PostAsync<PosOrderPaymentsRequest, PosOrderResponse>(
                $"pos/orders/{orderId}/payments",
                new PosOrderPaymentsRequest(payments));

            StatusMessage = response.Data.Status.Equals("paid", StringComparison.OrdinalIgnoreCase)
                ? $"Orden POS #{response.Data.Id} pagada y cerrada."
                : $"Orden POS #{response.Data.Id} actualizada. Sigue pendiente.";
            IsStatusError = false;
            await LoadPendingOrdersAsync();
            await SearchAsync();
            return response.Data;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
            throw;
        }
        catch (HttpRequestException exception)
        {
            const string message = "No se pudo conectar con la API para completar la orden.";
            SetError(message);
            throw new InvalidOperationException(message, exception);
        }
        finally
        {
            IsBusy = false;
        }
    }

    public void ClearCustomer()
    {
        SelectedCustomer = null;
        StatusMessage = "Cliente mostrador seleccionado.";
        IsStatusError = false;
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

            ResetQuoteCache();
            Products.Clear();
            foreach (InventoryProductItem product in response.Data.Products)
            {
                Products.Add(new PosProductCard(product));
            }

            StatusMessage = Products.Count == 0
                ? "No se encontraron productos para la búsqueda."
                : $"{Products.Count} productos disponibles para seleccionar.";
            StartQuoteWarmup();
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

    public async Task<IReadOnlyList<InventoryProductSerial>> LoadAvailableSerialsAsync(long productId, string search = "")
    {
        if (SelectedWarehouse is null)
        {
            SetError("Selecciona un almacén antes de buscar IMEI/seriales.");
            return [];
        }

        string query = BuildQuery([
            ("status", "available"),
            ("warehouse_id", SelectedWarehouse.Id.ToString(CultureInfo.InvariantCulture)),
            ("search", search),
            ("limit", "100"),
        ]);

        InventoryProductSerialsPageResponse response = await apiClient.GetAsync<InventoryProductSerialsPageResponse>(
            $"inventory-center/products/{productId}/serials{query}");

        return response.Data.Data;
    }

    public async Task AddProductAsync(PosProductCard card, InventoryProductSerial? selectedSerial = null)
    {
        if (SelectedWarehouse is null)
        {
            SetError("Selecciona un almacén antes de agregar productos.");
            return;
        }

        if (card.Product.Stock.Available <= 0)
        {
            SetError("No se puede agregar un producto sin stock disponible.");
            return;
        }

        if (card.Product.TrackingType == "serialized" && selectedSerial is null)
        {
            SetError("Este producto requiere seleccionar un IMEI/serial disponible.");
            return;
        }

        if (selectedSerial is not null && CartItems.Any(item => item.ProductUnitIds.Contains(selectedSerial.Id)))
        {
            SetError("Ese IMEI/serial ya está en la orden actual.");
            return;
        }

        try
        {
            IsBusy = true;
            IsStatusError = false;
            long? priceListId = SelectedPriceList?.Id;
            StatusMessage = HasCachedQuote(card.Product.Id, priceListId)
                ? "Agregando producto..."
                : "Cotizando producto...";

            PosPriceQuote quote = await GetQuoteAsync(card.Product.Id, priceListId);

            PosCartItem? existing = selectedSerial is null
                ? CartItems.FirstOrDefault(item =>
                    item.ProductId == card.Product.Id
                    && item.PriceListId == quote.PriceListId
                    && item.UnitPriceUsd == quote.PriceUsd
                    && item.WarehouseId == SelectedWarehouse.Id
                    && item.ProductUnitIds.Count == 0)
                : null;

            if (existing is not null)
            {
                existing.Increase();
            }
            else
            {
                PosCartItem item = new(card.Product, quote, SelectedWarehouse, selectedSerial);
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

    public IReadOnlyList<PaymentMethodOption> GetAllowedPaymentMethods()
    {
        PriceListOption? selectedList = SelectedPriceList;
        if (selectedList?.PaymentMethods is { Count: > 0 } restrictedMethods)
        {
            return restrictedMethods
                .Where(method => method.IsActive)
                .OrderBy(method => method.SortOrder)
                .ThenBy(method => method.Name)
                .ToList();
        }

        if (selectedList?.PaymentMethodIds is { Count: > 0 } restrictedIds)
        {
            HashSet<long> allowedIds = restrictedIds.ToHashSet();
            return PaymentMethods
                .Where(method => method.IsActive && allowedIds.Contains(method.Id))
                .OrderBy(method => method.SortOrder)
                .ThenBy(method => method.Name)
                .ToList();
        }

        return PaymentMethods
            .Where(method => method.IsActive)
            .OrderBy(method => method.SortOrder)
            .ThenBy(method => method.Name)
            .ToList();
    }

    public async Task<PosOrderResult> SubmitCheckoutAsync(IReadOnlyList<PosCheckoutPaymentRequest> payments)
    {
        if (SelectedCashRegisterSession is null)
        {
            throw new InvalidOperationException("Selecciona una caja abierta antes de cobrar.");
        }

        if (CartItems.Count == 0)
        {
            throw new InvalidOperationException("Agrega productos al carrito antes de cobrar.");
        }

        if (payments.Count == 0)
        {
            throw new InvalidOperationException("Agrega al menos un pago antes de confirmar.");
        }

        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Confirmando venta en el servidor...";

            PosCheckoutRequest request = new(
                SelectedCashRegisterSession.Id,
                SelectedCustomer?.Id,
                SelectedCustomer?.Name ?? "Cliente mostrador",
                CartItems.Select(item => new PosCheckoutItemRequest(
                    item.WarehouseId,
                    item.ProductId,
                    item.PriceListId,
                    item.Quantity,
                    item.ProductUnitIds)).ToList(),
                payments);

            PosOrderResponse response = await apiClient.PostAsync<PosCheckoutRequest, PosOrderResponse>("pos/checkouts", request);
            CartItems.Clear();
            RaiseTotalsChanged();
            StatusMessage = $"Venta confirmada. Orden POS #{response.Data.Id}.";
            IsStatusError = false;
            await SearchAsync();
            return response.Data;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
            throw;
        }
        catch (HttpRequestException exception)
        {
            SetError("No se pudo conectar con la API para confirmar la venta.");
            throw new InvalidOperationException("No se pudo conectar con la API para confirmar la venta.", exception);
        }
        finally
        {
            IsBusy = false;
            RaisePropertyChanged(nameof(CanPay));
        }
    }

    public void Increase(PosCartItem item)
    {
        if (item.ProductUnitIds.Count > 0)
        {
            StatusMessage = "Para productos con IMEI agrega otra unidad seleccionando otro serial.";
            IsStatusError = false;
            return;
        }

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
        RaisePropertyChanged(nameof(CanPay));
        RaisePropertyChanged(nameof(PayHint));
    }

    public void SetError(string message)
    {
        IsStatusError = true;
        StatusMessage = message;
        RaisePropertyChanged(nameof(CanPay));
        RaisePropertyChanged(nameof(PayHint));
    }

    private bool HasCachedQuote(long productId, long? priceListId)
    {
        lock (quoteSync)
        {
            return quoteCache.ContainsKey(new QuoteCacheKey(productId, priceListId));
        }
    }

    private Task<PosPriceQuote> GetQuoteAsync(long productId, long? priceListId)
    {
        QuoteCacheKey key = new(productId, priceListId);
        lock (quoteSync)
        {
            if (quoteCache.TryGetValue(key, out PosPriceQuote? cachedQuote))
            {
                return Task.FromResult(cachedQuote);
            }

            if (quoteRequests.TryGetValue(key, out Task<PosPriceQuote>? activeRequest))
            {
                return activeRequest;
            }

            Task<PosPriceQuote> request = FetchQuoteAsync(key);
            quoteRequests[key] = request;
            return request;
        }
    }

    private async Task<PosPriceQuote> FetchQuoteAsync(QuoteCacheKey key)
    {
        try
        {
            string priceQuery = key.PriceListId is null ? "" : $"?price_list_id={key.PriceListId}";
            PosPriceQuoteResponse response = await apiClient.GetAsync<PosPriceQuoteResponse>($"products/{key.ProductId}/price{priceQuery}");

            lock (quoteSync)
            {
                quoteCache[key] = response.Data;
            }

            return response.Data;
        }
        finally
        {
            lock (quoteSync)
            {
                quoteRequests.Remove(key);
            }
        }
    }

    private void ResetQuoteCache()
    {
        quoteWarmupCancellation?.Cancel();
        quoteWarmupCancellation?.Dispose();
        quoteWarmupCancellation = null;

        lock (quoteSync)
        {
            quoteCache.Clear();
            quoteRequests.Clear();
        }
    }

    private void StartQuoteWarmup()
    {
        quoteWarmupCancellation?.Cancel();
        quoteWarmupCancellation?.Dispose();
        quoteWarmupCancellation = null;

        List<PosProductCard> cards = Products
            .Where(card => card.Product.Stock.Available > 0 && card.Product.TrackingType != "serialized")
            .Take(18)
            .ToList();

        if (cards.Count == 0)
        {
            return;
        }

        quoteWarmupCancellation = new CancellationTokenSource();
        CancellationToken cancellationToken = quoteWarmupCancellation.Token;
        long? priceListId = SelectedPriceList?.Id;

        _ = WarmupQuotesAsync(cards, priceListId, cancellationToken);
    }

    private async Task WarmupQuotesAsync(IReadOnlyList<PosProductCard> cards, long? priceListId, CancellationToken cancellationToken)
    {
        using SemaphoreSlim gate = new(4);
        IEnumerable<Task> tasks = cards.Select(async card =>
        {
            try
            {
                await gate.WaitAsync(cancellationToken);
            }
            catch (OperationCanceledException)
            {
                return;
            }

            try
            {
                if (!cancellationToken.IsCancellationRequested)
                {
                    await GetQuoteAsync(card.Product.Id, priceListId);
                }
            }
            catch (ApiException)
            {
                // La precarga no debe interrumpir la venta; el click mostrará el error real si aplica.
            }
            catch (HttpRequestException)
            {
                // La precarga es oportunista. La acción manual seguirá mostrando errores visibles.
            }
            finally
            {
                gate.Release();
            }
        });

        await Task.WhenAll(tasks);
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

internal readonly record struct QuoteCacheKey(long ProductId, long? PriceListId);

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

    public PosCartItem(
        InventoryProductItem product,
        PosPriceQuote quote,
        InventoryWarehouseOption warehouse,
        InventoryProductSerial? serial)
    {
        ProductId = product.Id;
        Name = product.Name;
        Sku = product.Sku;
        WarehouseId = warehouse.Id;
        WarehouseLabel = warehouse.WarehouseLabel;
        PriceListId = quote.PriceListId;
        PriceListLabel = quote.PriceListLabel;
        UnitPriceUsd = quote.PriceUsd;
        UnitPriceVes = quote.PriceVes;
        ExchangeRateTypeId = quote.ExchangeRateTypeId;
        SaleCurrency = quote.SaleCurrency;
        SalePrice = quote.SalePrice;
        RateLabel = quote.RateLabel;
        ProductUnitIds = serial is null ? [] : [serial.Id];
        SerialLabel = serial is null ? "Por cantidad" : $"IMEI/serial: {serial.SerialNumber}";
        quantity = serial is null ? 1 : ProductUnitIds.Count;
    }

    public event EventHandler? Changed;

    public long ProductId { get; }

    public string Name { get; }

    public string Sku { get; }

    public long WarehouseId { get; }

    public string WarehouseLabel { get; }

    public long? PriceListId { get; }

    public string PriceListLabel { get; }

    public decimal UnitPriceUsd { get; }

    public decimal? UnitPriceVes { get; }

    public long? ExchangeRateTypeId { get; }

    public string SaleCurrency { get; }

    public decimal SalePrice { get; }

    public string RateLabel { get; }

    public IReadOnlyList<long> ProductUnitIds { get; }

    public string SerialLabel { get; }

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
