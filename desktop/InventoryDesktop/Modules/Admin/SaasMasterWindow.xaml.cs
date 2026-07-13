using System.Windows;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.Admin;

public partial class SaasMasterWindow : Window
{
    public SaasMasterWindow(ApiClient apiClient)
    {
        InitializeComponent();
        View.DataContext = new SaasMasterViewModel(apiClient);
        View.CloseRequested += (_, _) => Close();
    }
}