using System.Windows.Controls;
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

    private void ShowInventoryCenter()
    {
        SectionTitle.Text = "Centro de Inventario";
        SectionSubtitle.Text = "Datos reales desde el servidor";
        InventoryCenterContent.Visibility = System.Windows.Visibility.Visible;
        InventoryMovementsContent.Visibility = System.Windows.Visibility.Collapsed;
        InventoryCenterButton.Background = (System.Windows.Media.Brush)FindResource("AccentSoftBrush");
        InventoryCenterButton.Foreground = (System.Windows.Media.Brush)FindResource("AccentStrongBrush");
        InventoryMovementsButton.ClearValue(BackgroundProperty);
        InventoryMovementsButton.ClearValue(ForegroundProperty);
    }

    private void ShowInventoryMovements()
    {
        SectionTitle.Text = "Entradas y salidas";
        SectionSubtitle.Text = "Registra movimientos reales de stock por producto";
        InventoryCenterContent.Visibility = System.Windows.Visibility.Collapsed;
        InventoryMovementsContent.Visibility = System.Windows.Visibility.Visible;
        InventoryMovementsButton.Background = (System.Windows.Media.Brush)FindResource("AccentSoftBrush");
        InventoryMovementsButton.Foreground = (System.Windows.Media.Brush)FindResource("AccentStrongBrush");
        InventoryCenterButton.ClearValue(BackgroundProperty);
        InventoryCenterButton.ClearValue(ForegroundProperty);
    }
}
