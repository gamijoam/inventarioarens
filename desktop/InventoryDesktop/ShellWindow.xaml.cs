using System.Windows;
using InventoryDesktop.Core.Security;

namespace InventoryDesktop;

public partial class ShellWindow : Window
{
    public ShellWindow(DesktopSession session)
    {
        InitializeComponent();
        ShellContent.Content = new ShellView(session);
    }
}
