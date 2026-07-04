using System.Windows.Controls;
using System.Windows.Media;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop;

public partial class ShellView : UserControl
{
    private readonly InventoryCenterViewModel inventoryCenterViewModel;
    private readonly InventoryCenterViewModel inventoryMovementsViewModel;

    public ShellView(DesktopSession session)
    {
        InitializeComponent();
        DataContext = session;

        inventoryCenterViewModel = new InventoryCenterViewModel(session.ApiClient);
        inventoryMovementsViewModel = new InventoryCenterViewModel(session.ApiClient);
        InventoryCenterContent.DataContext = inventoryCenterViewModel;
        InventoryMovementsContent.DataContext = inventoryMovementsViewModel;
        PriceListsContent.Configure(session.ApiClient);
        Loaded += async (_, _) => await inventoryCenterViewModel.LoadAsync();
    }

    private async void InventoryCenter_Click(object sender, System.Windows.RoutedEventArgs e)
    {
        ShowInventoryCenter();
        await inventoryCenterViewModel.LoadAsync();
    }

    private async void InventoryMovements_Click(object sender, System.Windows.RoutedEventArgs e)
    {
        ShowInventoryMovements();
        await inventoryMovementsViewModel.LoadAsync();
    }

    private async void PriceLists_Click(object sender, System.Windows.RoutedEventArgs e)
    {
        ShowPriceLists();
        await PriceListsContent.LoadAsync();
    }

    private void ShowInventoryCenter()
    {
        SectionTitle.Text = "Centro de Inventario";
        SectionSubtitle.Text = "Datos reales desde el servidor";
        InventoryCenterContent.Visibility = System.Windows.Visibility.Visible;
        InventoryMovementsContent.Visibility = System.Windows.Visibility.Collapsed;
        PriceListsContent.Visibility = System.Windows.Visibility.Collapsed;
        SetActiveButton(InventoryCenterButton);
        SetInactiveButton(InventoryMovementsButton);
        SetInactiveButton(PriceListsButton);
    }

    private void ShowInventoryMovements()
    {
        SectionTitle.Text = "Entradas y salidas";
        SectionSubtitle.Text = "Registra movimientos reales de stock por producto";
        InventoryCenterContent.Visibility = System.Windows.Visibility.Collapsed;
        InventoryMovementsContent.Visibility = System.Windows.Visibility.Visible;
        PriceListsContent.Visibility = System.Windows.Visibility.Collapsed;
        SetActiveButton(InventoryMovementsButton);
        SetInactiveButton(InventoryCenterButton);
        SetInactiveButton(PriceListsButton);
    }

    private void ShowPriceLists()
    {
        SectionTitle.Text = "Listas de precio";
        SectionSubtitle.Text = "Configura precios para detal, mayor, técnico y futuras ventas POS";
        InventoryCenterContent.Visibility = System.Windows.Visibility.Collapsed;
        InventoryMovementsContent.Visibility = System.Windows.Visibility.Collapsed;
        PriceListsContent.Visibility = System.Windows.Visibility.Visible;
        SetActiveButton(PriceListsButton);
        SetInactiveButton(InventoryCenterButton);
        SetInactiveButton(InventoryMovementsButton);
    }

    private static void SetActiveButton(Button button)
    {
        button.Background = new SolidColorBrush(Color.FromRgb(238, 240, 255));
        button.Foreground = new SolidColorBrush(Color.FromRgb(53, 36, 223));
        button.BorderBrush = new SolidColorBrush(Color.FromRgb(220, 228, 255));
        button.FontWeight = System.Windows.FontWeights.Black;
    }

    private static void SetInactiveButton(Button button)
    {
        button.Background = new SolidColorBrush(Colors.Transparent);
        button.Foreground = new SolidColorBrush(Color.FromRgb(16, 23, 47));
        button.BorderBrush = new SolidColorBrush(Color.FromRgb(220, 228, 242));
        button.FontWeight = System.Windows.FontWeights.Bold;
    }
}
