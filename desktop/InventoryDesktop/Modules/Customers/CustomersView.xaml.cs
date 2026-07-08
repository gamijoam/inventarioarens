using System.Windows;
using System.Windows.Controls;

namespace InventoryDesktop.Modules.Customers;

public partial class CustomersView : UserControl
{
    public CustomersView()
    {
        InitializeComponent();
    }

    private CustomersViewModel? ViewModel => DataContext as CustomersViewModel;

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.LoadAsync();
        }
    }

    private async void ApplyFilters_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.LoadAsync();
        }
    }

    private async void ClearFilters_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        ViewModel.Search = string.Empty;
        ViewModel.ActiveOnly = true;
        await ViewModel.LoadAsync();
    }

    private async void NewCustomer_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        CustomerEditWindow window = new(ViewModel)
        {
            Owner = Window.GetWindow(this)
        };

        if (window.ShowDialog() == true)
        {
            await ViewModel.LoadAsync();
            SelectCustomer(window.SavedCustomer?.Id);
        }
    }

    private async void EditCustomer_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel?.SelectedCustomer is null)
        {
            MessageBox.Show(Window.GetWindow(this), "Selecciona un cliente para editar.", "Cliente requerido", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        CustomerEditWindow window = new(ViewModel, ViewModel.SelectedCustomer)
        {
            Owner = Window.GetWindow(this)
        };

        if (window.ShowDialog() == true)
        {
            await ViewModel.LoadAsync();
            SelectCustomer(window.SavedCustomer?.Id);
        }
    }

    private async void DeactivateCustomer_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel?.SelectedCustomer is null)
        {
            MessageBox.Show(Window.GetWindow(this), "Selecciona un cliente para desactivar.", "Cliente requerido", MessageBoxButton.OK, MessageBoxImage.Information);
            return;
        }

        if (ViewModel.SelectedCustomer.IsGeneric)
        {
            MessageBox.Show(Window.GetWindow(this), "El consumidor final no debe desactivarse desde esta vista.", "Cliente protegido", MessageBoxButton.OK, MessageBoxImage.Warning);
            return;
        }

        MessageBoxResult result = MessageBox.Show(
            Window.GetWindow(this),
            $"¿Desactivar a {ViewModel.SelectedCustomer.Name}? No se eliminará su historial.",
            "Confirmar desactivación",
            MessageBoxButton.YesNo,
            MessageBoxImage.Warning);

        if (result != MessageBoxResult.Yes)
        {
            return;
        }

        if (await ViewModel.DeactivateSelectedAsync())
        {
            await ViewModel.LoadAsync();
        }
    }

    private void SelectCustomer(long? customerId)
    {
        if (customerId is null || ViewModel is null)
        {
            return;
        }

        ViewModel.SelectedCustomer = ViewModel.Customers.FirstOrDefault(customer => customer.Id == customerId.Value);
    }
}
