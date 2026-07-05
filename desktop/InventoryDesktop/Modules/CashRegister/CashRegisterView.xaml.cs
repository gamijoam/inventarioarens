using System.Windows;
using System.Windows.Controls;

namespace InventoryDesktop.Modules.CashRegister;

public partial class CashRegisterView : UserControl
{
    public CashRegisterView()
    {
        InitializeComponent();
    }

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is CashRegisterViewModel viewModel)
        {
            await viewModel.LoadAsync();
        }
    }

    private async void Open_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is CashRegisterViewModel viewModel)
        {
            await viewModel.OpenCashRegisterAsync();
        }
    }
}
