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

    private async void CreateRegister_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is CashRegisterViewModel viewModel)
        {
            await viewModel.CreateCashRegisterAsync();
        }
    }

    private async void Close_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not CashRegisterViewModel viewModel)
        {
            return;
        }

        MessageBoxResult result = MessageBox.Show(
            "Vas a cerrar la caja seleccionada. Despues de cerrarla no podra usarse para vender en POS. ¿Deseas continuar?",
            "Confirmar cierre de caja",
            MessageBoxButton.YesNo,
            MessageBoxImage.Warning);

        if (result != MessageBoxResult.Yes)
        {
            return;
        }

        await viewModel.CloseCashRegisterAsync();
    }
}
