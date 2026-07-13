using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Reflection;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Threading;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Core.Services;
using InventoryDesktop.Modules.Admin;
using InventoryDesktop.Modules.CashRegister;
using InventoryDesktop.Modules.Currency;
using InventoryDesktop.Modules.Customers;
using InventoryDesktop.Modules.InventoryCenter;
using InventoryDesktop.Modules.InventoryTransfers;
using InventoryDesktop.Modules.POS;
using InventoryDesktop.Modules.Sync;
using MaterialDesignThemes.Wpf;

// Disambiguate: Sync namespace has its own TenantOption/LoginData duplicates
using AuthTenantOption = InventoryDesktop.Modules.Auth.TenantOption;
using AuthLoginData = InventoryDesktop.Modules.Auth.LoginData;

namespace InventoryDesktop;

public partial class ShellView : UserControl
{
    private readonly DesktopSession session;
    private readonly SessionService sessionService;
    private readonly InventoryCenterViewModel inventoryCenterViewModel;
    private readonly InventoryCenterViewModel inventoryMovementsViewModel;
    private readonly CashRegisterViewModel cashRegisterViewModel;
    private readonly CustomersViewModel customersViewModel;
    private readonly PosViewModel posViewModel;
    private bool initialSyncPromptShown;

    public ObservableCollection<ModuleCardInfo> ModuleCards { get; } = BuildModuleCards();

    public ShellView(DesktopSession session)
    {
        InitializeComponent();
        this.session = session;
        DataContext = session;

        sessionService = new SessionService(session.ApiClient, new TokenVault());

        inventoryCenterViewModel = new InventoryCenterViewModel(session.ApiClient);
        inventoryMovementsViewModel = new InventoryCenterViewModel(session.ApiClient);
        cashRegisterViewModel = new CashRegisterViewModel(session.ApiClient, session.Login.User.Id);
        customersViewModel = new CustomersViewModel(
            session.ApiClient,
            session.HasPermission("customers.create"),
            session.HasPermission("customers.update"),
            session.HasPermission("customers.delete"));
        posViewModel = new PosViewModel(session.ApiClient, session.Login.User.Id);
        InventoryCenterContent.DataContext = inventoryCenterViewModel;
        InventoryMovementsContent.DataContext = inventoryMovementsViewModel;
        CashRegisterContent.DataContext = cashRegisterViewModel;
        CustomersContent.DataContext = customersViewModel;
        PosContent.DataContext = posViewModel;
        PosContent.ExitRequested += (_, _) => ShowHome();
        InventoryCenterContent.BackToModulesRequested += (_, _) => ShowHome();
        InventoryMovementsContent.BackToModulesRequested += (_, _) => ShowHome();
        PriceListsContent.BackToModulesRequested += (_, _) => ShowHome();
        CurrencyRatesContent.BackToModulesRequested += (_, _) => ShowHome();
        CashRegisterContent.BackToModulesRequested += (_, _) => ShowHome();
        CustomersContent.BackToModulesRequested += (_, _) => ShowHome();
        PriceListsContent.Configure(session.ApiClient);
        CurrencyRatesContent.Configure(session.ApiClient, RunSyncForCurrentTenantAsync);
        TransferReceptionContent.Configure(session.ApiClient);
        SyncWorkerContent.Configure(session);
        Loaded += async (_, _) =>
        {
            ConfigureModulePermissions();
            ShowHome();
            await SyncWorkerContent.EnsureAutomaticWorkerAsync();
            await RefreshSyncIndicatorAsync();
            await PromptInitialSyncIfNeededAsync();
            await RefreshMeAsync();
        };
        Unloaded += async (_, _) =>
        {
            await LogoutAsync();
        };
    }

    private async Task RefreshMeAsync()
    {
        AuthLoginData? fresh = await sessionService.GetCurrentUserAsync();
        if (fresh is null)
        {
            return;
        }

        sessionService.PersistSession(new DesktopSession(
            ApiClient: session.ApiClient,
            Login: fresh,
            ApiBaseUrl: session.ApiBaseUrl));

        string label = fresh.Tenant is { } tenant
            ? tenant.Name
            : "Platform Admin";
        AppLogger.Info($"Sesion refrescada para '{label}'.");
    }

    private async Task LogoutAsync()
    {
        try
        {
            await sessionService.LogoutAsync();
        }
        catch (Exception exception)
        {
            AppLogger.Error("LogoutAsync fallo.", exception);
        }
    }

    private static ObservableCollection<ModuleCardInfo> BuildModuleCards()
    {
        return new ObservableCollection<ModuleCardInfo>
        {
            new("POS", "Punto de venta", "Venta rapida, carrito, caja y metodos de pago.",
                PackIconKind.PointOfSale, Color.FromRgb(0x4D, 0x35, 0xFF), nameof(Pos_Click)),

            new("Centro de Inventario", "Productos y stock", "Productos, stock, seriales, precios y detalle.",
                PackIconKind.PackageVariantClosed, Color.FromRgb(0x0E, 0xA5, 0xA4), nameof(InventoryCenter_Click)),

            new("Entradas y salidas", "Movimientos", "Movimientos operativos de productos y seriales.",
                PackIconKind.SwapHorizontal, Color.FromRgb(0x25, 0x63, 0xEB), nameof(InventoryMovements_Click)),

            new("Traslados", "Logistica", "Guias, despacho y recepcion logistica.",
                PackIconKind.TruckCargoContainer, Color.FromRgb(0x16, 0xA3, 0x4A), nameof(InventoryTransfers_Click)),

            new("Listas de precio", "Precios", "Detal, mayor, tecnico y reglas de cobro.",
                PackIconKind.TagMultiple, Color.FromRgb(0x7C, 0x3A, 0xED), nameof(PriceLists_Click)),

            new("Tasas", "BCV y paralelo", "BCV, paralelo y tasas vigentes sincronizadas.",
                PackIconKind.CashSync, Color.FromRgb(0x08, 0x91, 0xB2), nameof(CurrencyRates_Click)),

            new("Caja", "Apertura y cierre", "Aperturas, movimientos y cierre de caja.",
                PackIconKind.CashRegister, Color.FromRgb(0xF5, 0x9E, 0x0B), nameof(CashRegister_Click)),

            new("Clientes", "CRM", "Datos fiscales, contacto e historial POS.",
                PackIconKind.AccountGroupOutline, Color.FromRgb(0x14, 0xB8, 0xA6), nameof(Customers_Click)),

            new("Sincronizacion", "Worker local", "Estado, inicio y parada del worker local-nube.",
                PackIconKind.CloudSyncOutline, Color.FromRgb(0x63, 0x66, 0xF1), nameof(Sync_Click), IsHidden: true),

            new("Reportes", "Pronto", "Indicadores, ventas, inventario y finanzas.",
                PackIconKind.ChartLine, Color.FromRgb(0xDC, 0x26, 0x26), "", IsComingSoon: true),

            new("Configuracion", "Pronto", "Usuarios, permisos, empresa y parametros.",
                PackIconKind.CogOutline, Color.FromRgb(0x47, 0x55, 0x69), "", IsComingSoon: true),
        };
    }

    private void Home_Click(object sender, RoutedEventArgs e) => ShowHome();

    private async void RefreshHome_Click(object sender, RoutedEventArgs e)
    {
        ConfigureModulePermissions();
        await RefreshSyncIndicatorAsync();
        ShowHome();
    }

    private async void RunSyncNow_Click(object sender, RoutedEventArgs e) => await RunSyncForCurrentTenantAsync();

    private async Task RunSyncForCurrentTenantAsync()
    {
        RunSyncButton.IsEnabled = false;
        SyncProgressWindow? progressWindow = null;
        SyncStatusText.Text = "Sincronizando...";
        SyncStatusDot.Fill = new SolidColorBrush(Color.FromRgb(245, 158, 11));
        ShowInitialSyncNotice(
            "Sincronizando esta empresa",
            "Estamos enviando y descargando cambios para dejar esta instalacion lista. Puedes esperar unos segundos y luego abrir el modulo.");

        try
        {
            progressWindow = new SyncProgressWindow
            {
                Owner = Window.GetWindow(this)
            };
            progressWindow.Show();
            await SyncWorkerContent.RunOnceAsync();
            await RefreshSyncIndicatorAsync();

            if (IsSyncReady(SyncWorkerContent.Status))
            {
                progressWindow.MarkCompleted("Empresa lista en esta computadora. Ya puedes abrir los modulos disponibles.");
            }
            else
            {
                string message = BuildSyncPendingMessage(SyncWorkerContent.Message, SyncWorkerContent.StatusDetail);
                progressWindow.MarkPending(message);
            }
        }
        catch (Exception exception)
        {
            progressWindow?.MarkFailed(exception.Message);
            SetSyncIndicator("Error", exception.Message, exception.Message);
            ShowInitialSyncNotice("No se pudo sincronizar esta empresa", exception.Message);
        }
        finally
        {
            RunSyncButton.IsEnabled = true;
        }
    }

    private static bool IsSyncReady(string status)
    {
        return status.Equals("Sincronizado", StringComparison.OrdinalIgnoreCase) ||
            status.Equals("Activo", StringComparison.OrdinalIgnoreCase);
    }

    private static string BuildSyncPendingMessage(string message, string statusDetail)
    {
        if (!string.IsNullOrWhiteSpace(message) &&
            !message.Equals("Sincronizacion manual ejecutada.", StringComparison.OrdinalIgnoreCase))
        {
            return message;
        }

        if (!string.IsNullOrWhiteSpace(statusDetail))
        {
            return statusDetail;
        }

        return "La empresa sigue pendiente de sincronizacion inicial. Revisa la URL/token de nube o vuelve a intentar.";
    }

    private void ModuleCard_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button { DataContext: ModuleCardInfo card }) return;
        if (!card.IsClickable) return;

        var method = GetType().GetMethod(card.ClickHandler, BindingFlags.Instance | BindingFlags.NonPublic);
        method?.Invoke(this, new object?[] { this, new RoutedEventArgs() });
    }

    private async void InventoryCenter_Click(object sender, RoutedEventArgs e)
    {
        ShowInventoryCenter();
        await inventoryCenterViewModel.LoadAsync();
    }

    private async void InventoryMovements_Click(object sender, RoutedEventArgs e)
    {
        ShowInventoryMovements();
        await inventoryMovementsViewModel.LoadAsync();
    }

    private async void PriceLists_Click(object sender, RoutedEventArgs e)
    {
        ShowPriceLists();
        await PriceListsContent.LoadAsync();
    }

    private async void CurrencyRates_Click(object sender, RoutedEventArgs e)
    {
        ShowCurrencyRates();
        await CurrencyRatesContent.LoadAsync();
    }

    private async void InventoryTransfers_Click(object sender, RoutedEventArgs e)
    {
        ShowInventoryTransfers();
        await TransferReceptionContent.LoadAsync();
    }

    private async void CashRegister_Click(object sender, RoutedEventArgs e)
    {
        ShowCashRegister();
        await cashRegisterViewModel.LoadAsync();
    }

    private async void Customers_Click(object sender, RoutedEventArgs e)
    {
        ShowCustomers();
        await customersViewModel.LoadAsync();
    }

    private async void Pos_Click(object sender, RoutedEventArgs e)
    {
        using PerformanceTrace trace = PerformanceTrace.Start("Abrir módulo POS", 500);
        await posViewModel.InitializeAsync();
        if (posViewModel.SelectedCashRegisterSession?.HasPhysicalRegister != true)
        {
            MessageBoxResult result = MessageBox.Show(
                Window.GetWindow(this),
                "No tienes una caja fisica abierta asignada a tu usuario. Abre una caja desde el modulo Caja antes de entrar al POS.\n\nDeseas ir al modulo Caja ahora?",
                "Caja requerida",
                MessageBoxButton.YesNo,
                MessageBoxImage.Warning);

            if (result == MessageBoxResult.Yes && ModuleHasPermission("Caja"))
            {
                ShowCashRegister();
                await cashRegisterViewModel.LoadAsync();
            }
            else
            {
                ShowHome();
            }

            return;
        }

        ShowPos();
    }

    private async void Sync_Click(object sender, RoutedEventArgs e)
    {
        ShowSync();
        await SyncWorkerContent.LoadAsync();
    }

    private async void SwitchTenant_Click(object sender, RoutedEventArgs e)
    {
        if (!session.HasPermission("tenants.view"))
        {
            MessageBox.Show(
                Window.GetWindow(this),
                "No tienes permiso para cambiar de empresa.",
                "Acceso denegado",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
            return;
        }

        try
        {
            AuthTenantOption[] tenants = await TenantsApi.GetMyTenantsAsync(session.ApiClient);
            if (tenants.Length <= 1)
            {
                MessageBox.Show(
                    Window.GetWindow(this),
                    "Solo tienes una empresa asociada. No hay otras empresas a las que cambiar.",
                    "Cambiar empresa",
                    MessageBoxButton.OK,
                    MessageBoxImage.Information);
                return;
            }

            var dialog = new SwitchTenantDialog(tenants, session.TenantSlug)
            {
                Owner = Window.GetWindow(this),
            };
            if (dialog.ShowDialog() != true)
            {
                return;
            }

            string newTenantSlug = dialog.SelectedTenantSlug;
            if (string.IsNullOrWhiteSpace(newTenantSlug) || newTenantSlug == session.TenantSlug)
            {
                return;
            }

            AuthLoginData? fresh = await sessionService.SwitchTenantAsync(newTenantSlug);
            if (fresh is null)
            {
                MessageBox.Show(
                    Window.GetWindow(this),
                    "No se pudo cambiar de empresa.",
                    "Error",
                    MessageBoxButton.OK,
                    MessageBoxImage.Error);
                return;
            }

            Window owner = Window.GetWindow(this)
                ?? throw new InvalidOperationException("ShellView no esta dentro de una Window.");
            session.ApiClient.Configure(
                session.ApiBaseUrl,
                fresh.Token,
                fresh.Tenant?.Slug);
            var newSession = new DesktopSession(
                ApiClient: session.ApiClient,
                Login: fresh,
                ApiBaseUrl: session.ApiBaseUrl);
            owner.Content = new ShellView(newSession);
        }
        catch (Exception exception)
        {
            AppLogger.Error("SwitchTenant fallo.", exception);
            MessageBox.Show(
                Window.GetWindow(this),
                $"Error al cambiar de empresa: {exception.Message}",
                "Error",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
        }
    }

    private void SaasMaster_Click(object sender, RoutedEventArgs e)
    {
        if (!session.IsPlatformAdmin)
        {
            MessageBox.Show(
                Window.GetWindow(this),
                "Solo Platform Admin puede acceder al modo programador.",
                "Acceso denegado",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
            return;
        }

        var apiClientForAdmin = new ApiClient();
        apiClientForAdmin.Configure(session.ApiBaseUrl, session.Login.Token, tenantSlug: null);

        var window = new SaasMasterWindow(apiClientForAdmin)
        {
            Owner = Window.GetWindow(this),
        };
        window.Show();
    }

    private void ShowHome()
    {
        SectionTitle.Text = "Centro de módulos";
        SectionSubtitle.Text = "Selecciona el área de trabajo";
        BackToModulesButton.Visibility = Visibility.Collapsed;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Visible;
        HideAllModules();
    }

    private void ShowInventoryCenter()
    {
        ShowModule("Centro de Inventario", "Datos reales desde el servidor", InventoryCenterContent);
    }

    private void ShowInventoryMovements()
    {
        ShowModule("Entradas y salidas", "Registra movimientos reales de stock por producto", InventoryMovementsContent);
    }

    private void ShowPriceLists()
    {
        ShowModule("Listas de precio", "Configura precios para detal, mayor, técnico y futuras ventas POS", PriceListsContent);
    }

    private void ShowCurrencyRates()
    {
        ShowModule("Tasas", "Consulta tasas vigentes sincronizadas", CurrencyRatesContent);
    }

    private void ShowInventoryTransfers()
    {
        ShowModule("Traslados", "Recepcion logistica y guia de traslado", TransferReceptionContent);
    }

    private void ShowCashRegister()
    {
        ShowModule("Caja", "Apertura y control operativo para POS", CashRegisterContent);
    }

    private void ShowCustomers()
    {
        ShowModule("Clientes", "Datos fiscales, contacto e historial POS", CustomersContent);
    }

    private void ShowSync()
    {
        ShowModule("Sincronizacion", "Worker local-nube", SyncWorkerContent);
    }

    private void ShowModule(string title, string subtitle, FrameworkElement moduleContent)
    {
        SectionTitle.Text = title;
        SectionSubtitle.Text = subtitle;
        BackToModulesButton.Visibility = Visibility.Visible;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Collapsed;
        HideAllModules();
        moduleContent.Visibility = Visibility.Visible;
    }

    private void HideAllModules()
    {
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        CurrencyRatesContent.Visibility = Visibility.Collapsed;
        TransferReceptionContent.Visibility = Visibility.Collapsed;
        CashRegisterContent.Visibility = Visibility.Collapsed;
        CustomersContent.Visibility = Visibility.Collapsed;
        SyncWorkerContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowPos()
    {
        ShellHeader.Visibility = Visibility.Collapsed;
        HideAllModules();
        PosContent.Visibility = Visibility.Visible;
        PosContent.Focus();
        Keyboard.Focus(PosContent);
        PosContent.ActivateForSale();
        Dispatcher.BeginInvoke(
            () => PosContent.ActivateForSale(),
            DispatcherPriority.ContextIdle);
    }

    private void ConfigureModulePermissions()
    {
        var permissions = new Dictionary<string, (bool CanAccess, string DeniedText)>
        {
            ["POS"] = (session.HasAnyPermission("pos.view", "pos.checkout"), "Sin permiso POS"),
            ["Centro de Inventario"] = (session.HasPermission("products.view"), "Sin permiso de inventario"),
            ["Entradas y salidas"] = (session.HasAnyPermission("product_entries.create", "product_exits.create", "products.update"), "Sin permiso de movimientos"),
            ["Listas de precio"] = (session.HasPermission("products.update"), "Sin permiso de precios"),
            ["Tasas"] = (session.HasPermission("currency.view"), "Sin permiso de tasas"),
            ["Traslados"] = (session.HasAnyPermission("inventory_transfers.view", "inventory_transfers.receive"), "Sin permiso de traslados"),
            ["Caja"] = (session.HasAnyPermission("cash_register.view", "cash_register.open"), "Sin permiso de caja"),
            ["Clientes"] = (session.HasPermission("customers.view"), "Sin permiso de clientes"),
            ["Sincronizacion"] = (session.HasAnyPermission("sync.view", "sync.manage") || session.HasAnyPermission("cash_register.view", "products.view"), "Sin permiso de sincronizacion"),
        };

        foreach (var card in ModuleCards)
        {
            if (card.IsComingSoon || card.IsHidden) continue;

            if (permissions.TryGetValue(card.Title, out var perm))
            {
                card.UpdateAccess(perm.CanAccess, perm.DeniedText);
            }
        }

        bool canUseSync = permissions["Sincronizacion"].CanAccess;
        RunSyncButton.IsEnabled = canUseSync;
        RunSyncButton.ToolTip = canUseSync ? "Ejecuta un ciclo manual para esta empresa." : "Sin permiso de sincronizacion";
    }

    private bool ModuleHasPermission(string title)
    {
        var card = ModuleCards.FirstOrDefault(c => c.Title == title);
        return card?.IsClickable ?? false;
    }

    private async Task RefreshSyncIndicatorAsync()
    {
        try
        {
            await SyncWorkerContent.RefreshStatusAsync();
            SetSyncIndicator(SyncWorkerContent.Status, SyncWorkerContent.Message, SyncWorkerContent.StatusDetail);
            UpdateInitialSyncNotice(SyncWorkerContent.Status, SyncWorkerContent.StatusDetail);
        }
        catch
        {
            SetSyncIndicator("Error", "No se pudo consultar la sincronizacion.", "No se pudo consultar la sincronizacion.");
            ShowInitialSyncNotice(
                "No se pudo consultar la sincronizacion",
                "Revisa la conexion local o ejecuta Sincronizar ahora para volver a intentar."
            );
        }
    }

    private void SetSyncIndicator(string status, string message, string detail)
    {
        string visibleStatus = string.IsNullOrWhiteSpace(status) ? "Sin consultar" : status;
        string visibleDetail = string.IsNullOrWhiteSpace(message) ? detail : message;

        SyncStatusText.Text = visibleStatus;
        SyncStatusText.ToolTip = visibleDetail;
        SyncStatusDot.ToolTip = visibleDetail;

        SyncStatusDot.Fill = visibleStatus.ToLowerInvariant() switch
        {
            "sincronizado" => new SolidColorBrush(Color.FromRgb(5, 150, 105)),
            "activo" => new SolidColorBrush(Color.FromRgb(5, 150, 105)),
            "sincronizando" => new SolidColorBrush(Color.FromRgb(245, 158, 11)),
            "sincronizando..." => new SolidColorBrush(Color.FromRgb(245, 158, 11)),
            "pendiente" => new SolidColorBrush(Color.FromRgb(245, 158, 11)),
            "advertencia" => new SolidColorBrush(Color.FromRgb(245, 158, 11)),
            "detenido" => new SolidColorBrush(Color.FromRgb(245, 158, 11)),
            "error" => new SolidColorBrush(Color.FromRgb(220, 38, 38)),
            "no configurado" => new SolidColorBrush(Color.FromRgb(220, 38, 38)),
            _ => new SolidColorBrush(Color.FromRgb(148, 163, 184)),
        };
    }

    private void UpdateInitialSyncNotice(string status, string detail)
    {
        string normalized = (status ?? "").Trim().ToLowerInvariant();

        switch (normalized)
        {
            case "sincronizado":
            case "activo":
                InitialSyncNotice.Visibility = Visibility.Collapsed;
                return;
            case "sincronizando":
            case "sincronizando...":
                ShowInitialSyncNotice(
                    "Sincronizacion en proceso",
                    "Esta empresa se esta preparando en esta computadora. Al terminar quedara lista para operar."
                );
                return;
            case "advertencia":
                ShowInitialSyncNotice(
                    "Sincronizacion con advertencias",
                    string.IsNullOrWhiteSpace(detail)
                        ? "La empresa puede operar, pero hay eventos que requieren revision."
                        : detail
                );
                return;
            case "error":
            case "no configurado":
                ShowInitialSyncNotice(
                    "Sincronizacion pendiente de resolver",
                    string.IsNullOrWhiteSpace(detail)
                        ? "No se pudo completar la sincronizacion de esta empresa en esta computadora."
                        : detail
                );
                return;
            default:
                ShowInitialSyncNotice(
                    "Esta empresa necesita sincronizacion inicial",
                    "Sincroniza esta instalacion para descargar productos, precios, cajas y permisos antes de operar."
                );
                return;
        }
    }

    private void ShowInitialSyncNotice(string title, string detail)
    {
        InitialSyncTitle.Text = title;
        InitialSyncDetail.Text = detail;
        InitialSyncNotice.Visibility = Visibility.Visible;
    }

    private async Task PromptInitialSyncIfNeededAsync()
    {
        if (initialSyncPromptShown || !RunSyncButton.IsEnabled)
        {
            return;
        }

        string status = (SyncWorkerContent.Status ?? "").Trim().ToLowerInvariant();
        bool needsInitialSync = status is "" or "sin consultar" or "pendiente" or "error" or "no configurado";

        if (!needsInitialSync)
        {
            return;
        }

        initialSyncPromptShown = true;
        MessageBoxResult result = MessageBox.Show(
            Window.GetWindow(this),
            "Esta empresa todavia no esta lista en esta computadora.\n\nQuieres sincronizarla ahora para descargar productos, precios, cajas y permisos?",
            "Sincronizacion inicial",
            MessageBoxButton.YesNo,
            MessageBoxImage.Information);

        if (result == MessageBoxResult.Yes)
        {
            await RunSyncForCurrentTenantAsync();
        }
    }
}

public sealed class ModuleCardInfo : INotifyPropertyChanged
{
    private bool isClickable;
    private string toolTip = string.Empty;
    private double opacity = 1.0;

    public event PropertyChangedEventHandler? PropertyChanged;

    public string Title { get; }
    public string Subtitle { get; }
    public string Description { get; }
    public PackIconKind Icon { get; }
    public Brush IconBrush { get; }
    public string ClickHandler { get; }
    public bool IsComingSoon { get; }
    public bool IsHidden { get; }

    public Visibility ComingSoonVisibility => IsComingSoon ? Visibility.Visible : Visibility.Collapsed;
    public Visibility CardVisibility => IsHidden ? Visibility.Collapsed : Visibility.Visible;

    public bool IsClickable
    {
        get => isClickable;
        private set
        {
            if (isClickable == value) return;
            isClickable = value;
            PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(IsClickable)));
            PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(Opacity)));
        }
    }

    public string ToolTip
    {
        get => toolTip;
        private set
        {
            if (toolTip == value) return;
            toolTip = value;
            PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(ToolTip)));
        }
    }

    public double Opacity
    {
        get => IsClickable ? opacity : 0.55;
        private set
        {
            if (Math.Abs(opacity - value) < 0.01) return;
            opacity = value;
            PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(Opacity)));
        }
    }

    public ModuleCardInfo(
        string title,
        string subtitle,
        string description,
        PackIconKind icon,
        Color iconColor,
        string clickHandler,
        bool IsComingSoon = false,
        bool IsHidden = false)
    {
        Title = title;
        Subtitle = subtitle;
        Description = description;
        Icon = icon;
        IconBrush = new SolidColorBrush(iconColor);
        ClickHandler = clickHandler;
        this.IsComingSoon = IsComingSoon;
        this.IsHidden = IsHidden;
        isClickable = !IsComingSoon && !IsHidden && !string.IsNullOrEmpty(clickHandler);
    }

    public void UpdateAccess(bool canAccess, string deniedText)
    {
        IsClickable = canAccess;
        ToolTip = canAccess ? string.Empty : deniedText;
    }
}
