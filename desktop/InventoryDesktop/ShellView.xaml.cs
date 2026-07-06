using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.CashRegister;
using InventoryDesktop.Modules.InventoryCenter;
using InventoryDesktop.Modules.POS;
using InventoryDesktop.Modules.Sync;

namespace InventoryDesktop;

public partial class ShellView : UserControl
{
    private readonly DesktopSession session;
    private readonly InventoryCenterViewModel inventoryCenterViewModel;
    private readonly InventoryCenterViewModel inventoryMovementsViewModel;
    private readonly CashRegisterViewModel cashRegisterViewModel;
    private readonly PosViewModel posViewModel;
    private bool initialSyncPromptShown;

    public ShellView(DesktopSession session)
    {
        InitializeComponent();
        this.session = session;
        DataContext = session;

        inventoryCenterViewModel = new InventoryCenterViewModel(session.ApiClient);
        inventoryMovementsViewModel = new InventoryCenterViewModel(session.ApiClient);
        cashRegisterViewModel = new CashRegisterViewModel(session.ApiClient, session.Login.User.Id);
        posViewModel = new PosViewModel(session.ApiClient, session.Login.User.Id);
        InventoryCenterContent.DataContext = inventoryCenterViewModel;
        InventoryMovementsContent.DataContext = inventoryMovementsViewModel;
        CashRegisterContent.DataContext = cashRegisterViewModel;
        PosContent.DataContext = posViewModel;
        PosContent.ExitRequested += (_, _) => ShowHome();
        PriceListsContent.Configure(session.ApiClient);
        SyncWorkerContent.Configure(session);
        Loaded += async (_, _) =>
        {
            ConfigureModulePermissions();
            ShowHome();
            await RefreshSyncIndicatorAsync();
            await PromptInitialSyncIfNeededAsync();
        };
    }

    private void Home_Click(object sender, RoutedEventArgs e)
    {
        ShowHome();
    }

    private async void RefreshHome_Click(object sender, RoutedEventArgs e)
    {
        ConfigureModulePermissions();
        await RefreshSyncIndicatorAsync();
        ShowHome();
    }

    private async void RunSyncNow_Click(object sender, RoutedEventArgs e)
    {
        await RunSyncForCurrentTenantAsync();
    }

    private async Task RunSyncForCurrentTenantAsync()
    {
        RunSyncButton.IsEnabled = false;
        SyncProgressWindow? progressWindow = null;
        SyncStatusText.Text = "Sincronizando...";
        SyncStatusDot.Fill = new SolidColorBrush(Color.FromRgb(245, 158, 11));
        ShowInitialSyncNotice(
            "Sincronizando esta empresa",
            "Estamos enviando y descargando cambios para dejar esta instalacion lista. Puedes esperar unos segundos y luego abrir el modulo."
        );

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

    private async void InventoryCenter_Click(object sender, RoutedEventArgs e)
    {
        if (!HomeInventoryCard.IsEnabled)
        {
            return;
        }

        ShowInventoryCenter();
        await inventoryCenterViewModel.LoadAsync();
    }

    private async void InventoryMovements_Click(object sender, RoutedEventArgs e)
    {
        if (!HomeMovementsCard.IsEnabled)
        {
            return;
        }

        ShowInventoryMovements();
        await inventoryMovementsViewModel.LoadAsync();
    }

    private async void PriceLists_Click(object sender, RoutedEventArgs e)
    {
        if (!HomePriceListsCard.IsEnabled)
        {
            return;
        }

        ShowPriceLists();
        await PriceListsContent.LoadAsync();
    }

    private async void CashRegister_Click(object sender, RoutedEventArgs e)
    {
        if (!HomeCashCard.IsEnabled)
        {
            return;
        }

        ShowCashRegister();
        await cashRegisterViewModel.LoadAsync();
    }

    private async void Pos_Click(object sender, RoutedEventArgs e)
    {
        if (!HomePosCard.IsEnabled)
        {
            return;
        }

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

            if (result == MessageBoxResult.Yes && HomeCashCard.IsEnabled)
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
        if (!HomeSyncCard.IsEnabled)
        {
            return;
        }

        ShowSync();
        await SyncWorkerContent.LoadAsync();
    }

    private void ShowHome()
    {
        SectionTitle.Text = "Centro de módulos";
        SectionSubtitle.Text = "Selecciona el área de trabajo";
        BackToModulesButton.Visibility = Visibility.Collapsed;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Visible;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        CashRegisterContent.Visibility = Visibility.Collapsed;
        SyncWorkerContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowInventoryCenter()
    {
        SectionTitle.Text = "Centro de Inventario";
        SectionSubtitle.Text = "Datos reales desde el servidor";
        BackToModulesButton.Visibility = Visibility.Visible;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Visible;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        CashRegisterContent.Visibility = Visibility.Collapsed;
        SyncWorkerContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowInventoryMovements()
    {
        SectionTitle.Text = "Entradas y salidas";
        SectionSubtitle.Text = "Registra movimientos reales de stock por producto";
        BackToModulesButton.Visibility = Visibility.Visible;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Visible;
        PriceListsContent.Visibility = Visibility.Collapsed;
        CashRegisterContent.Visibility = Visibility.Collapsed;
        SyncWorkerContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowPriceLists()
    {
        SectionTitle.Text = "Listas de precio";
        SectionSubtitle.Text = "Configura precios para detal, mayor, técnico y futuras ventas POS";
        BackToModulesButton.Visibility = Visibility.Visible;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Visible;
        CashRegisterContent.Visibility = Visibility.Collapsed;
        SyncWorkerContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowCashRegister()
    {
        SectionTitle.Text = "Caja";
        SectionSubtitle.Text = "Apertura y control operativo para POS";
        BackToModulesButton.Visibility = Visibility.Visible;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        CashRegisterContent.Visibility = Visibility.Visible;
        SyncWorkerContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowSync()
    {
        SectionTitle.Text = "Sincronizacion";
        SectionSubtitle.Text = "Worker local-nube";
        BackToModulesButton.Visibility = Visibility.Visible;
        ShellHeader.Visibility = Visibility.Visible;
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        CashRegisterContent.Visibility = Visibility.Collapsed;
        SyncWorkerContent.Visibility = Visibility.Visible;
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowPos()
    {
        ShellHeader.Visibility = Visibility.Collapsed;
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        CashRegisterContent.Visibility = Visibility.Collapsed;
        SyncWorkerContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Visible;
    }

    private void ConfigureModulePermissions()
    {
        bool canUsePos = session.HasAnyPermission("pos.view", "pos.checkout");
        bool canViewInventory = session.HasPermission("products.view");
        bool canMoveInventory = session.HasAnyPermission("product_entries.create", "product_exits.create", "products.update");
        bool canManagePrices = session.HasPermission("products.update");
        bool canUseCashRegister = session.HasAnyPermission("cash_register.view", "cash_register.open");
        bool canUseSync = session.HasAnyPermission("sync.view", "sync.manage") || session.HasAnyPermission("cash_register.view", "products.view");

        SetCardAccess(HomePosCard, canUsePos, "Sin permiso POS");
        SetCardAccess(HomeInventoryCard, canViewInventory, "Sin permiso de inventario");
        SetCardAccess(HomeMovementsCard, canMoveInventory, "Sin permiso de movimientos");
        SetCardAccess(HomePriceListsCard, canManagePrices, "Sin permiso de precios");
        SetCardAccess(HomeCashCard, canUseCashRegister, "Sin permiso de caja");
        SetCardAccess(HomeSyncCard, canUseSync, "Sin permiso de sincronizacion");
        RunSyncButton.IsEnabled = canUseSync;
        RunSyncButton.ToolTip = canUseSync ? "Ejecuta un ciclo manual para esta empresa." : "Sin permiso de sincronizacion";
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

    private static void SetCardAccess(Button cardButton, bool canAccess, string deniedText)
    {
        cardButton.IsEnabled = canAccess;
        cardButton.ToolTip = canAccess ? null : deniedText;
        cardButton.Opacity = canAccess ? 1 : 0.55;
    }
}
