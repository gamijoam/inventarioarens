using System.Windows.Controls;
using InventoryDesktop.Core.Security;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop;

public partial class ShellView : UserControl
{
    private readonly InventoryCenterViewModel inventoryCenterViewModel;

    public ShellView(DesktopSession session)
    {
        InitializeComponent();
        DataContext = session;

        inventoryCenterViewModel = new InventoryCenterViewModel(session.ApiClient);
        InventoryCenterContent.DataContext = inventoryCenterViewModel;
        Loaded += async (_, _) => await inventoryCenterViewModel.LoadAsync();
    }
}
