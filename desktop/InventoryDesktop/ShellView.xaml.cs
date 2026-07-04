using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.InventoryCenter;
using InventoryDesktop.Modules.POS;

namespace InventoryDesktop;

public partial class ShellView : UserControl
{
    private readonly DesktopSession session;
    private readonly InventoryCenterViewModel inventoryCenterViewModel;
    private readonly InventoryCenterViewModel inventoryMovementsViewModel;
    private readonly PosViewModel posViewModel;

    public ShellView(DesktopSession session)
    {
        InitializeComponent();
        this.session = session;
        DataContext = session;

        inventoryCenterViewModel = new InventoryCenterViewModel(session.ApiClient);
        inventoryMovementsViewModel = new InventoryCenterViewModel(session.ApiClient);
        posViewModel = new PosViewModel(session.ApiClient);
        InventoryCenterContent.DataContext = inventoryCenterViewModel;
        InventoryMovementsContent.DataContext = inventoryMovementsViewModel;
        PosContent.DataContext = posViewModel;
        PosContent.ExitRequested += (_, _) => ShowHome();
        PriceListsContent.Configure(session.ApiClient);
        Loaded += (_, _) =>
        {
            ConfigureModulePermissions();
            ShowHome();
        };
    }

    private void Home_Click(object sender, RoutedEventArgs e)
    {
        ShowHome();
    }

    private void RefreshHome_Click(object sender, RoutedEventArgs e)
    {
        ConfigureModulePermissions();
        ShowHome();
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

    private async void Pos_Click(object sender, RoutedEventArgs e)
    {
        if (!HomePosCard.IsEnabled)
        {
            return;
        }

        ShowPos();
        await posViewModel.InitializeAsync();
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
        PosContent.Visibility = Visibility.Collapsed;
    }

    private void ShowPos()
    {
        ShellHeader.Visibility = Visibility.Collapsed;
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Visible;
    }

    private void ConfigureModulePermissions()
    {
        bool canUsePos = session.HasAnyPermission("pos.view", "pos.checkout");
        bool canViewInventory = session.HasPermission("products.view");
        bool canMoveInventory = session.HasAnyPermission("product_entries.create", "product_exits.create", "products.update");
        bool canManagePrices = session.HasPermission("products.update");

        SetCardAccess(HomePosCard, canUsePos, "Sin permiso POS");
        SetCardAccess(HomeInventoryCard, canViewInventory, "Sin permiso de inventario");
        SetCardAccess(HomeMovementsCard, canMoveInventory, "Sin permiso de movimientos");
        SetCardAccess(HomePriceListsCard, canManagePrices, "Sin permiso de precios");
    }

    private static void SetCardAccess(Button cardButton, bool canAccess, string deniedText)
    {
        cardButton.IsEnabled = canAccess;
        cardButton.ToolTip = canAccess ? null : deniedText;
        cardButton.Opacity = canAccess ? 1 : 0.55;
    }
}
