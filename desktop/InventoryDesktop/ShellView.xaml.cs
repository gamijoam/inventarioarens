using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
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
        if (!CanOpen(InventoryCenterButton))
        {
            return;
        }

        ShowInventoryCenter();
        await inventoryCenterViewModel.LoadAsync();
    }

    private async void InventoryMovements_Click(object sender, RoutedEventArgs e)
    {
        if (!CanOpen(InventoryMovementsButton))
        {
            return;
        }

        ShowInventoryMovements();
        await inventoryMovementsViewModel.LoadAsync();
    }

    private async void PriceLists_Click(object sender, RoutedEventArgs e)
    {
        if (!CanOpen(PriceListsButton))
        {
            return;
        }

        ShowPriceLists();
        await PriceListsContent.LoadAsync();
    }

    private async void Pos_Click(object sender, RoutedEventArgs e)
    {
        if (!CanOpen(PosButton))
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
        HomeContent.Visibility = Visibility.Visible;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
        SetActiveButton(HomeButton);
        SetInactiveButton(InventoryCenterButton);
        SetInactiveButton(InventoryMovementsButton);
        SetInactiveButton(PriceListsButton);
        SetInactiveButton(PosButton);
    }

    private void ShowInventoryCenter()
    {
        SectionTitle.Text = "Centro de Inventario";
        SectionSubtitle.Text = "Datos reales desde el servidor";
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Visible;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
        SetActiveButton(InventoryCenterButton);
        SetInactiveButton(HomeButton);
        SetInactiveButton(InventoryMovementsButton);
        SetInactiveButton(PriceListsButton);
        SetInactiveButton(PosButton);
    }

    private void ShowInventoryMovements()
    {
        SectionTitle.Text = "Entradas y salidas";
        SectionSubtitle.Text = "Registra movimientos reales de stock por producto";
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Visible;
        PriceListsContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Collapsed;
        SetActiveButton(InventoryMovementsButton);
        SetInactiveButton(HomeButton);
        SetInactiveButton(InventoryCenterButton);
        SetInactiveButton(PriceListsButton);
        SetInactiveButton(PosButton);
    }

    private void ShowPriceLists()
    {
        SectionTitle.Text = "Listas de precio";
        SectionSubtitle.Text = "Configura precios para detal, mayor, técnico y futuras ventas POS";
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Visible;
        PosContent.Visibility = Visibility.Collapsed;
        SetActiveButton(PriceListsButton);
        SetInactiveButton(HomeButton);
        SetInactiveButton(InventoryCenterButton);
        SetInactiveButton(InventoryMovementsButton);
        SetInactiveButton(PosButton);
    }

    private void ShowPos()
    {
        SectionTitle.Text = "POS";
        SectionSubtitle.Text = "Búsqueda rápida, listas de precio y carrito de venta";
        HomeContent.Visibility = Visibility.Collapsed;
        InventoryCenterContent.Visibility = Visibility.Collapsed;
        InventoryMovementsContent.Visibility = Visibility.Collapsed;
        PriceListsContent.Visibility = Visibility.Collapsed;
        PosContent.Visibility = Visibility.Visible;
        SetActiveButton(PosButton);
        SetInactiveButton(HomeButton);
        SetInactiveButton(InventoryCenterButton);
        SetInactiveButton(InventoryMovementsButton);
        SetInactiveButton(PriceListsButton);
    }

    private void ConfigureModulePermissions()
    {
        bool canUsePos = session.HasAnyPermission("pos.view", "pos.checkout");
        bool canViewInventory = session.HasPermission("products.view");
        bool canMoveInventory = session.HasAnyPermission("product_entries.create", "product_exits.create", "products.update");
        bool canManagePrices = session.HasPermission("products.update");

        SetModuleAccess(PosButton, HomePosCard, canUsePos, "Sin permiso POS");
        SetModuleAccess(InventoryCenterButton, HomeInventoryCard, canViewInventory, "Sin permiso de inventario");
        SetModuleAccess(InventoryMovementsButton, HomeMovementsCard, canMoveInventory, "Sin permiso de movimientos");
        SetModuleAccess(PriceListsButton, HomePriceListsCard, canManagePrices, "Sin permiso de precios");
    }

    private static void SetModuleAccess(Button sideButton, Button cardButton, bool canAccess, string deniedText)
    {
        sideButton.IsEnabled = canAccess;
        cardButton.IsEnabled = canAccess;
        cardButton.ToolTip = canAccess ? null : deniedText;
        cardButton.Opacity = canAccess ? 1 : 0.55;
    }

    private static bool CanOpen(Button button)
    {
        return button.IsEnabled;
    }

    private static void SetActiveButton(Button button)
    {
        if (!button.IsEnabled)
        {
            return;
        }

        button.Background = new SolidColorBrush(Color.FromRgb(238, 240, 255));
        button.Foreground = new SolidColorBrush(Color.FromRgb(53, 36, 223));
        button.BorderBrush = new SolidColorBrush(Color.FromRgb(220, 228, 255));
        button.FontWeight = FontWeights.Black;
    }

    private static void SetInactiveButton(Button button)
    {
        if (!button.IsEnabled)
        {
            button.Background = new SolidColorBrush(Color.FromRgb(247, 249, 253));
            button.Foreground = new SolidColorBrush(Color.FromRgb(148, 163, 184));
            button.BorderBrush = new SolidColorBrush(Color.FromRgb(225, 231, 243));
            button.FontWeight = FontWeights.Bold;
            return;
        }

        button.Background = new SolidColorBrush(Colors.Transparent);
        button.Foreground = new SolidColorBrush(Color.FromRgb(16, 23, 47));
        button.BorderBrush = new SolidColorBrush(Color.FromRgb(220, 228, 242));
        button.FontWeight = FontWeights.Bold;
    }
}
