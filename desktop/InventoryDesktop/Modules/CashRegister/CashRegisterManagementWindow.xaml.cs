using System.Windows;

namespace InventoryDesktop.Modules.CashRegister;

public partial class CashRegisterManagementWindow : Window
{
    public CashRegisterManagementWindow(CashRegisterViewModel viewModel)
    {
        InitializeComponent();
        DataContext = viewModel;
    }

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is CashRegisterViewModel viewModel)
        {
            await viewModel.LoadAsync();
        }
    }

    private async void Create_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is CashRegisterViewModel viewModel)
        {
            await viewModel.CreateCashRegisterAsync();
        }
    }

    private async void Update_Click(object sender, RoutedEventArgs e)
    {
        if (DataContext is not CashRegisterViewModel viewModel)
        {
            return;
        }

        MessageBoxResult result = MessageBox.Show(
            this,
            "Vas a guardar cambios en esta caja fisica. Si la desactivas no podra abrir turnos nuevos. Deseas continuar?",
            "Confirmar cambios",
            MessageBoxButton.YesNo,
            MessageBoxImage.Question);

        if (result != MessageBoxResult.Yes)
        {
            return;
        }

        await viewModel.UpdateCashRegisterAsync();
    }

    private void CloseWindow_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}
