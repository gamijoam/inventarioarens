using System.Windows;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop;

public partial class ShellWindow : Window
{
    private readonly InventoryCenterViewModel inventoryCenterViewModel;

    public ShellWindow(DesktopSession session)
    {
        InitializeComponent();
        DataContext = session;

        inventoryCenterViewModel = new InventoryCenterViewModel(session.ApiClient);
        InventoryCenterContent.DataContext = inventoryCenterViewModel;
        Loaded += async (_, _) => await inventoryCenterViewModel.LoadAsync();
    }
}
