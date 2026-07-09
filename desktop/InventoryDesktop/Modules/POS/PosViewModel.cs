using System.Collections.ObjectModel;
using System.Globalization;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.ViewModels;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public sealed class PosViewModel : ViewModelBase
{
    private const int QuoteWarmupLimit = 8;
    private const int QuoteWarmupConcurrency = 2;

    private static readonly PriceListOption BasePriceOption = new(
        0,
        "Precio base",
        "BASE",
        "Usa el precio normal configurado en el producto.",
        false,
        true,
        -1);

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
    private PosCustomerHistory? selectedCustomerHistory;
    private bool isLoadingCustomerHistory;
    private PosReceiptSnapshot? lastReceipt;
    private CancellationTokenSource? quoteWarmupCancellation;
    private bool hasInitialized;

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

    public ObservableCollection<PosCustomerRecentOrder> CustomerRecentOrders { get; } = new();

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
                StartCartRequote();
            }
        }
    }

    public string PriceListLabel => SelectedPriceList is null
        ? "Lista: Precio base"
        : $"Lista: {SelectedPriceList.Name}";

    private long? SelectedPriceListId => SelectedPriceList is null || SelectedPriceList.Id <= 0
        ? null
        : SelectedPriceList.Id;

    public InventoryWarehouseOption? SelectedWarehouse
    {
        get => selectedWarehouse;
        set
        {
            if (SetProperty(ref selectedWarehouse, value))
            {
                if (SelectedCashRegisterSession is not null
                    && SelectedWarehouse?.BranchId is long branchId
                    && SelectedCashRegisterSession.BranchId != branchId)
                {
                    SelectedCashRegisterSession = null;
                    SetError("El almacen cambio. Abre una caja fisica de esa sucursal desde el modulo Caja.");
                }

                RaisePropertyChanged(nameof(OperationalContextLabel));
                RaiseOperationalContextChanged();
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
                RaiseOperationalContextChanged();
            }
        }
    }

    public string OperationalContextLabel
    {
        get
        {
            string warehouse = SelectedWarehouse?.WarehouseLabel ?? "Sin almacen";
            string cashRegister = SelectedCashRegisterSession?.DisplayLabel ?? "Sin caja abierta";
            return $"{warehouse} - {cashRegister}";
        }
    }

    public string ActiveCashRegisterLabel => SelectedCashRegisterSession is null
        ? "Sin caja abierta"
        : SelectedCashRegisterSession.DisplayLabel;

    public string CashRegisterStatusTitle => SelectedCashRegisterSession is null
        ? "Caja requerida"
        : "Caja abierta";

    public string CashRegisterStatusDetail => SelectedCashRegisterSession is null
        ? "Abre tu caja desde el modulo Caja para poder cobrar ventas."
        : $"{SelectedCashRegisterSession.DisplayLabel} lista para vender.";

    public string CashRegisterStatusBadge => SelectedCashRegisterSession is null ? "SIN CAJA" : "ABIERTA";

    public Brush CashRegisterStatusBrush => SelectedCashRegisterSession is null
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(5, 133, 97));

    public string WarehouseStatusDetail => SelectedWarehouse is null
        ? "Selecciona un almacén antes de vender."
        : SelectedWarehouse.WarehouseLabel;

    public bool HasOperationalContext => SelectedWarehouse is not null && SelectedCashRegisterSession?.HasPhysicalRegister == true;

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

    public string CustomerLabel => SelectedCustomer?.Name ?? "Consumidor final";

    public string CustomerDetailLabel => SelectedCustomer?.DetailLabel ?? "Venta sin cliente registrado";

    public PosCustomerHistory? SelectedCustomerHistory
    {
        get => selectedCustomerHistory;
        private set
        {
            if (SetProperty(ref selectedCustomerHistory, value))
            {
                RaiseCustomerHistoryChanged();
            }
        }
    }

    public bool IsLoadingCustomerHistory
    {
        get => isLoadingCustomerHistory;
        private set
        {
            if (SetProperty(ref isLoadingCustomerHistory, value))
            {
                RaisePropertyChanged(nameof(CustomerHistorySummaryLabel));
            }
        }
    }

    public string CustomerHistorySummaryLabel
    {
        get
        {
            if (IsLoadingCustomerHistory)
            {
                return "Consultando historial...";
            }

            return SelectedCustomerHistory?.OrdersLabel ?? "Selecciona un cliente para ver su historial.";
        }
    }

    public string CustomerHistoryPaidLabel => SelectedCustomerHistory?.PaidLabel ?? "Pagadas: 0";

    public string CustomerHistoryOpenLabel => SelectedCustomerHistory?.OpenLabel ?? "Pendientes: 0";

    public string CustomerHistoryMoneyLabel => SelectedCustomerHistory?.TotalLabel ?? "Total USD 0.00";

    public string CustomerHistoryDebtLabel => SelectedCustomerHistory?.BalanceLabel ?? "Saldo USD 0.00";

    public string CustomerHistoryLastLabel => SelectedCustomerHistory?.LastOrderLabel ?? "Sin compras registradas";

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

    public bool CanPay => CartItems.Count > 0 && HasOperationalContext && !IsBusy;

    public PosReceiptSnapshot? LastReceipt
    {
        get => lastReceipt;
        private set
        {
            if (SetProperty(ref lastReceipt, value))
            {
                RaisePropertyChanged(nameof(HasLastReceipt));
            }
        }
    }

    public bool HasLastReceipt => LastReceipt is not null;

    public string PayHint
    {
        get
        {
            if (CartItems.Count == 0)
            {
                return "Agrega productos para poder cobrar.";
            }

            if (SelectedWarehouse is null)
            {
                return "Selecciona un almacén antes de cobrar.";
            }

            if (SelectedCashRegisterSession?.HasPhysicalRegister != true)
            {
                return "No tienes una caja fisica abierta asignada a tu usuario. Abrela desde el modulo Caja.";
            }

            return "Abre la ventana de cobro para pagos en USD, Bs o mixtos.";
        }
    }

    public void StoreLastReceipt(PosReceiptSnapshot receipt)
    {
        LastReceipt = receipt;
        StatusMessage = $"Ultimo recibo disponible: {receipt.OrderLabel}.";
        IsStatusError = false;
    }

    public async Task InitializeAsync()
    {
        using PerformanceTrace trace = PerformanceTrace.Start("POS InitializeAsync", 700);
        IsBusy = true;
        IsStatusError = false;
        StatusMessage = hasInitialized
            ? "Actualizando POS..."
            : "Cargando POS...";

        try
        {
            if (!hasInitialized)
            {
                await Task.WhenAll(
                    LoadPriceListsAsync(),
                    LoadWarehousesAsync());
                hasInitialized = true;
            }

            await LoadCashRegisterSessionsAsync();
            ClearProductResults("POS listo. Escanea, escribe un código o usa F2 para buscar productos.");
        }
        finally
        {
            IsBusy = false;
        }
    }

    public async Task LoadOperationalContextAsync(bool forceStaticRefresh = false)
    {
        await LoadWarehousesAsync(forceStaticRefresh);
        await LoadCashRegisterSessionsAsync();
    }

    public async Task LoadPriceListsAsync(bool forceRefresh = false)
    {
        if (!forceRefresh && PriceLists.Count > 1)
        {
            return;
        }

        try
        {
            PriceListListResponse response = await apiClient.GetAsync<PriceListListResponse>("price-lists?active_only=1");
            long? selectedId = SelectedPriceList?.Id;
            PriceLists.Clear();
            PriceLists.Add(BasePriceOption);
            foreach (PriceListOption priceList in response.Data.Where(list => list.IsActive))
            {
                PriceLists.Add(priceList);
            }

            SelectedPriceList = selectedId is not null
                ? PriceLists.FirstOrDefault(list => list.Id == selectedId.Value) ?? BasePriceOption
                : BasePriceOption;
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

    public async Task LoadWarehousesAsync(bool forceRefresh = false)
    {
        if (!forceRefresh && Warehouses.Count > 0)
        {
            return;
        }

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
            long? branchId = SelectedWarehouse?.BranchId;
            List<PosCashRegisterSession> openSessions = response.Data
                .Where(session => session.Status == "open" && session.CashierId == currentUserId)
                .Where(session => session.HasPhysicalRegister)
                .Where(session => branchId is null || session.BranchId == branchId.Value)
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
                SetError(branchId is null
                    ? "Selecciona un almacén para cargar la caja de esa sucursal."
                    : "No tienes una caja fisica abierta para la sucursal del almacen seleccionado. Abrela desde el modulo Caja antes de vender.");
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
        await Task.CompletedTask;
        SetError("La apertura de caja se gestiona desde el modulo Caja. Abre una caja fisica y vuelve al POS.");
    }

    public async Task LoadPaymentMethodsAsync(bool forceRefresh = false)
    {
        if (!forceRefresh && PaymentMethods.Count > 0)
        {
            return;
        }

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

    public async Task LoadCustomerHistoryAsync(PosCustomerOption? customer)
    {
        CustomerRecentOrders.Clear();
        SelectedCustomerHistory = null;

        if (customer is null)
        {
            return;
        }

        try
        {
            IsLoadingCustomerHistory = true;
            PosCustomerDetailResponse response = await apiClient.GetAsync<PosCustomerDetailResponse>($"customers/{customer.Id}?include=pos_history");
            PosCustomerHistory? history = response.Data.PosHistory;
            SelectedCustomerHistory = history;

            foreach (PosCustomerRecentOrder order in history?.RecentOrders ?? [])
            {
                CustomerRecentOrders.Add(order);
            }
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar el historial del cliente.");
        }
        finally
        {
            IsLoadingCustomerHistory = false;
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
            foreach (PosOrderSummary order in response.Data
                .Where(order => order.CashierId == currentUserId)
                .OrderByDescending(order => order.Id))
            {
                PendingOrders.Add(order);
            }

            StatusMessage = PendingOrders.Count == 0
                ? "No hay ordenes POS pendientes para tu caja."
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

            string resultMessage = response.Data.Status.Equals("paid", StringComparison.OrdinalIgnoreCase)
                ? $"Orden POS #{response.Data.Id} pagada y cerrada."
                : $"Orden POS #{response.Data.Id} actualizada. Sigue pendiente.";
            StatusMessage = resultMessage;
            IsStatusError = false;
            await LoadPendingOrdersAsync();
            SearchText = string.Empty;
            ClearProductResults(resultMessage);
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

    public async Task<PosOrderResult> CancelPendingOrderAsync(long orderId)
    {
        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Cancelando orden pendiente...";

            PosOrderResponse response = await apiClient.PostAsync<PosCancelOrderRequest, PosOrderResponse>(
                $"pos/orders/{orderId}/cancel",
                new PosCancelOrderRequest());

            StatusMessage = $"Orden POS #{response.Data.Id} cancelada. La reserva fue liberada.";
            IsStatusError = false;
            await LoadPendingOrdersAsync();
            return response.Data;
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
            throw;
        }
        catch (HttpRequestException exception)
        {
            const string message = "No se pudo conectar con la API para cancelar la orden.";
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
        StatusMessage = "Consumidor final seleccionado.";
        IsStatusError = false;
    }

    public async Task SearchAsync()
    {
        using PerformanceTrace trace = PerformanceTrace.Start("POS buscar productos", 450);
        try
        {
            string search = SearchText.Trim();
            if (string.IsNullOrWhiteSpace(search))
            {
                ClearProductResults("Escanea o escribe para buscar productos. No se carga catálogo completo al abrir el POS.");
                return;
            }

            if (search.Length < 2)
            {
                ClearProductResults("Escribe al menos 2 caracteres para buscar productos.");
                return;
            }

            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Buscando productos...";

            string query = BuildQuery([
                ("search", search),
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

    private void ClearProductResults(string message)
    {
        ResetQuoteCache();
        Products.Clear();
        StatusMessage = message;
        IsStatusError = false;
    }

    public PosProductCard? FindExactSearchMatch()
    {
        string search = NormalizeExactSearch(SearchText);
        if (string.IsNullOrWhiteSpace(search))
        {
            return null;
        }

        List<PosProductCard> matches = Products
            .Where(card => ProductMatchesExactSearch(card.Product, search))
            .ToList();

        return matches.Count == 1 ? matches[0] : null;
    }

    public async Task<InventoryProductSerial?> FindExactAvailableSerialAsync(PosProductCard card, string serialText)
    {
        string search = NormalizeExactSearch(serialText);
        if (string.IsNullOrWhiteSpace(search))
        {
            return null;
        }

        IReadOnlyList<InventoryProductSerial> serials = await LoadAvailableSerialsAsync(card.Product.Id, serialText);
        return serials.FirstOrDefault(serial => NormalizeExactSearch(serial.SerialNumber) == search);
    }

    public async Task<ExactSerialSearchMatch?> FindExactSerialSearchMatchAsync()
    {
        string search = NormalizeExactSearch(SearchText);
        if (string.IsNullOrWhiteSpace(search))
        {
            return null;
        }

        List<ExactSerialSearchMatch> matches = [];
        foreach (PosProductCard card in Products.Where(card => card.Product.TrackingType == "serialized").Take(8))
        {
            InventoryProductSerial? serial = await FindExactAvailableSerialAsync(card, SearchText);
            if (serial is not null)
            {
                matches.Add(new ExactSerialSearchMatch(card, serial));
            }
        }

        if (matches.Count > 1)
        {
            SetError("El IMEI escaneado aparece en mas de un resultado. Abre el selector y confirma el producto.");
            return null;
        }

        return matches.FirstOrDefault();
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
        using PerformanceTrace trace = PerformanceTrace.Start($"POS agregar producto {card.Product.Sku}", 250);
        if (SelectedWarehouse is null)
        {
            SetError("Selecciona un almacén antes de agregar productos.");
            return;
        }

        if (!HasStockAvailableForCart(card))
        {
            SetError(BuildNoStockMessage(card));
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
            long? priceListId = SelectedPriceListId;
            StatusMessage = HasCachedQuote(card.Product.Id, priceListId)
                ? "Agregando producto..."
                : "Cotizando producto...";

            PosPriceQuote quote = await GetQuoteAsync(card.Product, priceListId);
            card.SetQuote(quote);

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
            if (exception.Message.Contains("Este producto no tiene precio en esta lista", StringComparison.OrdinalIgnoreCase))
            {
                card.SetNoPriceInList();
            }
            else if (exception.Message.Contains("precio base", StringComparison.OrdinalIgnoreCase))
            {
                card.SetNoBasePrice();
            }

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

    public bool HasStockAvailableForCart(PosProductCard card)
    {
        return RemainingStockForCart(card) >= 1;
    }

    public string BuildNoStockMessage(PosProductCard card)
    {
        return card.Product.Stock.Available <= 0
            ? $"{card.Product.Name} no tiene stock disponible para vender."
            : $"No puedes agregar mas unidades de {card.Product.Name}; el stock disponible ya esta en el carrito.";
    }

    private decimal RemainingStockForCart(PosProductCard card)
    {
        decimal quantityAlreadyInCart = CartItems
            .Where(item => item.ProductId == card.Product.Id)
            .Sum(item => item.Quantity);

        return card.Product.Stock.Available - quantityAlreadyInCart;
    }

    public void ClearCart()
    {
        CartItems.Clear();
        RaiseTotalsChanged();
        StatusMessage = "Carrito limpiado.";
        IsStatusError = false;
    }

    private void StartCartRequote()
    {
        if (CartItems.Count == 0)
        {
            return;
        }

        _ = RequoteCartAsync();
    }

    private async Task RequoteCartAsync()
    {
        try
        {
            IsBusy = true;
            IsStatusError = false;
            StatusMessage = "Actualizando precios del carrito...";

            long? priceListId = SelectedPriceListId;
            foreach (PosCartItem item in CartItems.ToList())
            {
                PosPriceQuote quote = await GetQuoteAsync(item.Product, priceListId);
                item.ApplyQuote(quote);
            }

            RaiseTotalsChanged();
            StatusMessage = "Precios del carrito actualizados.";
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para actualizar precios del carrito.");
        }
        finally
        {
            IsBusy = false;
        }
    }

    public IReadOnlyList<PaymentMethodOption> GetAllowedPaymentMethods()
    {
        PriceListOption? selectedList = SelectedPriceListId is null ? null : SelectedPriceList;
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
            using PerformanceTrace trace = PerformanceTrace.Start("POS checkout backend", 1000);
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
                    item.ProductUnitIds,
                    item.DiscountType,
                    item.DiscountValueForRequest,
                    item.DiscountReasonForRequest)).ToList(),
                payments);

            PosOrderResponse response = await apiClient.PostAsync<PosCheckoutRequest, PosOrderResponse>("pos/checkouts", request);
            CartItems.Clear();
            SelectedCustomer = null;
            RaiseTotalsChanged();
            SearchText = string.Empty;
            ClearProductResults($"Venta confirmada. Orden POS #{response.Data.Id}. Escanea o busca para iniciar otra venta.");
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

    private void RaiseCustomerHistoryChanged()
    {
        RaisePropertyChanged(nameof(CustomerHistorySummaryLabel));
        RaisePropertyChanged(nameof(CustomerHistoryPaidLabel));
        RaisePropertyChanged(nameof(CustomerHistoryOpenLabel));
        RaisePropertyChanged(nameof(CustomerHistoryMoneyLabel));
        RaisePropertyChanged(nameof(CustomerHistoryDebtLabel));
        RaisePropertyChanged(nameof(CustomerHistoryLastLabel));
    }

    private void RaiseOperationalContextChanged()
    {
        RaisePropertyChanged(nameof(CashRegisterStatusTitle));
        RaisePropertyChanged(nameof(CashRegisterStatusDetail));
        RaisePropertyChanged(nameof(CashRegisterStatusBadge));
        RaisePropertyChanged(nameof(CashRegisterStatusBrush));
        RaisePropertyChanged(nameof(ActiveCashRegisterLabel));
        RaisePropertyChanged(nameof(WarehouseStatusDetail));
        RaisePropertyChanged(nameof(HasOperationalContext));
        RaisePropertyChanged(nameof(CanPay));
        RaisePropertyChanged(nameof(PayHint));
    }

    public void SetError(string message)
    {
        IsStatusError = true;
        StatusMessage = message;
        RaiseOperationalContextChanged();
    }

    private bool HasCachedQuote(long productId, long? priceListId)
    {
        lock (quoteSync)
        {
            return quoteCache.ContainsKey(new QuoteCacheKey(productId, priceListId));
        }
    }

    private Task<PosPriceQuote> GetQuoteAsync(InventoryProductItem product, long? priceListId)
    {
        QuoteCacheKey key = new(product.Id, priceListId);
        lock (quoteSync)
        {
            if (quoteCache.TryGetValue(key, out PosPriceQuote? cachedQuote))
            {
                return Task.FromResult(cachedQuote);
            }

            if (TryBuildFastBaseQuote(product, priceListId) is PosPriceQuote fastQuote)
            {
                quoteCache[key] = fastQuote;
                return Task.FromResult(fastQuote);
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

    private static PosPriceQuote? TryBuildFastBaseQuote(InventoryProductItem product, long? priceListId)
    {
        if (priceListId is not null || product.BasePrice is null)
        {
            return null;
        }

        if (!product.SaleCurrency.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            return null;
        }

        return new PosPriceQuote(
            product.Id,
            null,
            null,
            "base",
            product.BasePrice.Value,
            "USD",
            product.BasePrice.Value,
            product.BasePrice.Value,
            null,
            null,
            null,
            null,
            null);
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

        long? priceListId = SelectedPriceListId;
        List<PosProductCard> cards = Products.ToList();

        foreach (PosProductCard card in cards)
        {
            card.PreparePrice(priceListId);
        }

        if (cards.Count == 0 || priceListId is null)
        {
            return;
        }

        List<PosProductCard> warmupCards = cards
            .Where(card => card.Product.Stock.Available > 0)
            .Take(QuoteWarmupLimit)
            .ToList();

        foreach (PosProductCard card in warmupCards)
        {
            card.SetQuoteLoading();
        }

        if (warmupCards.Count == 0)
        {
            return;
        }

        quoteWarmupCancellation = new CancellationTokenSource();
        CancellationToken cancellationToken = quoteWarmupCancellation.Token;

        _ = WarmupQuotesAsync(warmupCards, priceListId, cancellationToken);
    }

    private async Task WarmupQuotesAsync(IReadOnlyList<PosProductCard> cards, long? priceListId, CancellationToken cancellationToken)
    {
        using SemaphoreSlim gate = new(QuoteWarmupConcurrency);
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
                    PosPriceQuote quote = await GetQuoteAsync(card.Product, priceListId);
                    if (!cancellationToken.IsCancellationRequested)
                    {
                        card.SetQuote(quote);
                    }
                }
            }
            catch (ApiException exception)
            {
                if (!cancellationToken.IsCancellationRequested)
                {
                    if (exception.Message.Contains("Este producto no tiene precio en esta lista", StringComparison.OrdinalIgnoreCase))
                    {
                        card.SetNoPriceInList();
                    }
                    else if (exception.Message.Contains("precio base", StringComparison.OrdinalIgnoreCase))
                    {
                        card.SetNoBasePrice();
                    }
                    else
                    {
                        card.SetQuoteError();
                    }
                }
            }
            catch (HttpRequestException)
            {
                if (!cancellationToken.IsCancellationRequested)
                {
                    card.SetQuoteError();
                }
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

    private static bool ProductMatchesExactSearch(InventoryProductItem product, string search)
    {
        return NormalizeExactSearch(product.Sku) == search
            || NormalizeExactSearch(product.Name) == search;
    }

    private static string NormalizeExactSearch(string? value)
    {
        return string.IsNullOrWhiteSpace(value)
            ? string.Empty
            : value.Trim().ToUpperInvariant();
    }
}

internal readonly record struct QuoteCacheKey(long ProductId, long? PriceListId);

public sealed record ExactSerialSearchMatch(PosProductCard Card, InventoryProductSerial Serial);

public sealed class PosProductCard(InventoryProductItem product)
    : ViewModelBase
{
    private string cardPriceLabel = product.BasePrice is null ? "Sin precio base" : product.PriceLabel;
    private Brush cardPriceBrush = new SolidColorBrush(Color.FromRgb(49, 38, 238));

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

    public Brush StockBrush => Product.Stock.Available <= 0
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(5, 133, 97));

    public string CardPriceLabel
    {
        get => cardPriceLabel;
        private set => SetProperty(ref cardPriceLabel, value);
    }

    public Brush CardPriceBrush
    {
        get => cardPriceBrush;
        private set => SetProperty(ref cardPriceBrush, value);
    }

    public string TrackingShortLabel => Product.TrackingType == "serialized" ? "IMEI" : "Cantidad";

    public void PreparePrice(long? priceListId)
    {
        if (priceListId is null)
        {
            SetBasePrice();
            return;
        }

        CardPriceLabel = "Cotizar al tocar";
        CardPriceBrush = new SolidColorBrush(Color.FromRgb(100, 113, 140));
    }

    public void SetQuoteLoading()
    {
        CardPriceLabel = "Cotizando...";
        CardPriceBrush = new SolidColorBrush(Color.FromRgb(100, 113, 140));
    }

    public void SetQuote(PosPriceQuote quote)
    {
        CardPriceLabel = $"{quote.SaleCurrency} {quote.SalePrice:0.00}";
        CardPriceBrush = new SolidColorBrush(Color.FromRgb(49, 38, 238));
    }

    public void SetNoPriceInList()
    {
        CardPriceLabel = "Sin precio en lista";
        CardPriceBrush = new SolidColorBrush(Color.FromRgb(217, 54, 92));
    }

    public void SetNoBasePrice()
    {
        CardPriceLabel = "Sin precio base";
        CardPriceBrush = new SolidColorBrush(Color.FromRgb(217, 54, 92));
    }

    public void SetQuoteError()
    {
        CardPriceLabel = "Error al cotizar";
        CardPriceBrush = new SolidColorBrush(Color.FromRgb(217, 54, 92));
    }

    private void SetBasePrice()
    {
        CardPriceLabel = Product.BasePrice is null ? "Sin precio base" : Product.PriceLabel;
        CardPriceBrush = Product.BasePrice is null
            ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
            : new SolidColorBrush(Color.FromRgb(49, 38, 238));
    }
}

public sealed class PosCartItem : ViewModelBase
{
    private decimal quantity = 1;
    private string? discountType;
    private decimal discountValue;
    private string? discountReason;

    public PosCartItem(
        InventoryProductItem product,
        PosPriceQuote quote,
        InventoryWarehouseOption warehouse,
        InventoryProductSerial? serial)
    {
        Product = product;
        ProductId = product.Id;
        Name = product.Name;
        Sku = product.Sku;
        WarehouseId = warehouse.Id;
        WarehouseLabel = warehouse.WarehouseLabel;
        ApplyQuote(quote, notify: false);
        ProductUnitIds = serial is null ? [] : [serial.Id];
        SerialNumber = serial?.SerialNumber;
        ControlLabel = serial is null ? "Por cantidad" : "Serializado / IMEI";
        SerialLabel = serial is null ? "Venta por cantidad" : $"IMEI: {serial.SerialNumber}";
        quantity = serial is null ? 1 : ProductUnitIds.Count;
    }

    public event EventHandler? Changed;

    public InventoryProductItem Product { get; }

    public long ProductId { get; }

    public string Name { get; }

    public string Sku { get; }

    public long WarehouseId { get; }

    public string WarehouseLabel { get; }

    public long? PriceListId { get; private set; }

    public string PriceListLabel { get; private set; } = "";

    public decimal UnitPriceUsd { get; private set; }

    public decimal? UnitPriceVes { get; private set; }

    public long? ExchangeRateTypeId { get; private set; }

    public string SaleCurrency { get; private set; } = "USD";

    public decimal SalePrice { get; private set; }

    public string RateLabel { get; private set; } = "";

    public IReadOnlyList<long> ProductUnitIds { get; }

    public string? SerialNumber { get; }

    public string ControlLabel { get; }

    public string SerialLabel { get; }

    public bool IsSerialized => ProductUnitIds.Count > 0;

    public bool CanChangeQuantity => !IsSerialized;

    public string QuantityActionHint => IsSerialized
        ? "Para vender otra unidad selecciona otro IMEI."
        : "Cantidad editable.";

    public string? DiscountType
    {
        get => discountType;
        private set => SetProperty(ref discountType, value);
    }

    public decimal DiscountValue
    {
        get => discountValue;
        private set => SetProperty(ref discountValue, value);
    }

    public string? DiscountReason
    {
        get => discountReason;
        private set => SetProperty(ref discountReason, value);
    }

    public decimal? DiscountValueForRequest => HasDiscount ? DiscountValue : null;

    public string? DiscountReasonForRequest => HasDiscount ? DiscountReason : null;

    public bool HasDiscount => !string.IsNullOrWhiteSpace(DiscountType) && DiscountValue > 0;

    public decimal Quantity
    {
        get => quantity;
        private set
        {
            if (SetProperty(ref quantity, value))
            {
                RaisePropertyChanged(nameof(QuantityLabel));
                RaiseMoneyPropertiesChanged();
                Changed?.Invoke(this, EventArgs.Empty);
            }
        }
    }

    public string QuantityLabel => Quantity.ToString("0.##", CultureInfo.CurrentCulture);

    public decimal GrossTotalUsd => UnitPriceUsd * Quantity;

    public decimal? GrossTotalVes => UnitPriceVes is null ? null : UnitPriceVes.Value * Quantity;

    public decimal DiscountUsd => CalculateDiscountUsd();

    public decimal? DiscountVes => CalculateDiscountVes();

    public decimal TotalUsd => Math.Max(0m, GrossTotalUsd - DiscountUsd);

    public decimal? TotalVes => GrossTotalVes is null ? null : Math.Max(0m, GrossTotalVes.Value - (DiscountVes ?? 0m));

    public string UnitPriceLabel => $"{SaleCurrency} {SalePrice:0.00}";

    public string TotalLabel => $"USD {TotalUsd:0.00}";

    public string DiscountLabel => HasDiscount
        ? $"Desc. {DiscountDisplayAmountLabel} · {DiscountReason}"
        : "Sin descuento";

    public void ApplyQuote(PosPriceQuote quote)
    {
        ApplyQuote(quote, notify: true);
    }

    private void ApplyQuote(PosPriceQuote quote, bool notify)
    {
        PriceListId = quote.PriceListId;
        PriceListLabel = quote.PriceListLabel;
        UnitPriceUsd = quote.PriceUsd;
        UnitPriceVes = quote.PriceVes;
        ExchangeRateTypeId = quote.ExchangeRateTypeId;
        SaleCurrency = quote.SaleCurrency;
        SalePrice = quote.SalePrice;
        RateLabel = quote.RateLabel;

        if (!notify)
        {
            return;
        }

        RaisePropertyChanged(nameof(PriceListId));
        RaisePropertyChanged(nameof(PriceListLabel));
        RaisePropertyChanged(nameof(UnitPriceUsd));
        RaisePropertyChanged(nameof(UnitPriceVes));
        RaisePropertyChanged(nameof(ExchangeRateTypeId));
        RaisePropertyChanged(nameof(SaleCurrency));
        RaisePropertyChanged(nameof(SalePrice));
        RaisePropertyChanged(nameof(RateLabel));
        RaisePropertyChanged(nameof(UnitPriceLabel));
        RaiseMoneyPropertiesChanged();
        Changed?.Invoke(this, EventArgs.Empty);
    }

    public void ApplyDiscount(string type, decimal value, string reason)
    {
        DiscountType = type;
        DiscountValue = value;
        DiscountReason = string.IsNullOrWhiteSpace(reason) ? "Sin motivo" : reason.Trim();
        RaiseMoneyPropertiesChanged();
        Changed?.Invoke(this, EventArgs.Empty);
    }

    public void ClearDiscount()
    {
        DiscountType = null;
        DiscountValue = 0m;
        DiscountReason = null;
        RaiseMoneyPropertiesChanged();
        Changed?.Invoke(this, EventArgs.Empty);
    }

    public void Increase()
    {
        Quantity += 1;
    }

    public void Decrease()
    {
        Quantity -= 1;
    }

    private string DiscountDisplayAmountLabel
    {
        get
        {
            if (!HasDiscount)
            {
                return "0";
            }

            return DiscountType == "percent"
                ? $"{DiscountValue:0.##}%"
                : $"{SaleCurrency} {DiscountValue:0.00}";
        }
    }

    private decimal CalculateDiscountUsd()
    {
        if (!HasDiscount)
        {
            return 0m;
        }

        if (DiscountType == "percent")
        {
            return Math.Min(GrossTotalUsd, Math.Round(GrossTotalUsd * DiscountValue / 100m, 4));
        }

        if (SaleCurrency.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            return Math.Min(GrossTotalUsd, DiscountValue);
        }

        if (UnitPriceVes is null || UnitPriceVes <= 0 || UnitPriceUsd <= 0)
        {
            return 0m;
        }

        decimal rate = UnitPriceVes.Value / UnitPriceUsd;
        return Math.Min(GrossTotalUsd, Math.Round(DiscountValue / rate, 4));
    }

    private decimal? CalculateDiscountVes()
    {
        if (GrossTotalVes is null)
        {
            return null;
        }

        if (!HasDiscount)
        {
            return 0m;
        }

        if (DiscountType == "percent")
        {
            return Math.Min(GrossTotalVes.Value, Math.Round(GrossTotalVes.Value * DiscountValue / 100m, 4));
        }

        if (SaleCurrency.Equals("VES", StringComparison.OrdinalIgnoreCase))
        {
            return Math.Min(GrossTotalVes.Value, DiscountValue);
        }

        if (UnitPriceUsd <= 0)
        {
            return 0m;
        }

        decimal rate = GrossTotalVes.Value / GrossTotalUsd;
        return Math.Min(GrossTotalVes.Value, Math.Round(DiscountValue * rate, 4));
    }

    private void RaiseMoneyPropertiesChanged()
    {
        RaisePropertyChanged(nameof(GrossTotalUsd));
        RaisePropertyChanged(nameof(GrossTotalVes));
        RaisePropertyChanged(nameof(DiscountUsd));
        RaisePropertyChanged(nameof(DiscountVes));
        RaisePropertyChanged(nameof(TotalUsd));
        RaisePropertyChanged(nameof(TotalVes));
        RaisePropertyChanged(nameof(TotalLabel));
        RaisePropertyChanged(nameof(DiscountLabel));
        RaisePropertyChanged(nameof(HasDiscount));
        RaisePropertyChanged(nameof(DiscountValueForRequest));
        RaisePropertyChanged(nameof(DiscountReasonForRequest));
    }
}
