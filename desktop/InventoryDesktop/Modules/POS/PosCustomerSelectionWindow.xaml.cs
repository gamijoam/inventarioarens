using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;

namespace InventoryDesktop.Modules.POS;

public partial class PosCustomerSelectionWindow : Window
{
    private readonly PosViewModel viewModel;
    private long historyRequestId;

    public PosCustomerSelectionWindow(PosViewModel viewModel)
    {
        InitializeComponent();
        this.viewModel = viewModel;
        DataContext = viewModel;
        CustomersGrid.ItemsSource = viewModel.CustomerSearchResults;
        PreviewKeyDown += CustomerSelectionWindow_PreviewKeyDown;
        SearchBox.Focus();
    }

    public PosCustomerOption? SelectedCustomer { get; private set; }

    public bool UseWalkInCustomer { get; private set; }

    private async void Search_Click(object sender, RoutedEventArgs e)
    {
        await SearchAsync();
    }

    private async void SearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            await SearchAsync();
        }
    }

    private void CustomerSelectionWindow_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Escape)
        {
            DialogResult = false;
            Close();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F2)
        {
            SearchBox.Focus();
            SearchBox.SelectAll();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.Enter && Keyboard.FocusedElement != SearchBox)
        {
            SelectCurrentCustomer();
            e.Handled = true;
        }
    }

    private async Task SearchAsync()
    {
        StatusText.Text = string.Empty;
        IReadOnlyList<PosCustomerOption> results = await viewModel.SearchCustomersAsync(SearchBox.Text);
        StatusText.Text = results.Count == 0 ? "No se encontraron clientes activos." : $"{results.Count} cliente(s) encontrados.";
        CustomersGrid.SelectedIndex = results.Count > 0 ? 0 : -1;
    }

    private void CreateCustomer_Click(object sender, RoutedEventArgs e)
    {
        PosCustomerCreateWindow dialog = new(viewModel)
        {
            Owner = this,
        };

        bool? result = dialog.ShowDialog();
        if (result == true && dialog.CreatedCustomer is not null)
        {
            SelectedCustomer = dialog.CreatedCustomer;
            DialogResult = true;
            Close();
        }
    }

    private void CustomersGrid_MouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        SelectCurrentCustomer();
    }

    private async void CustomersGrid_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        long requestId = ++historyRequestId;
        PosCustomerOption? customer = CustomersGrid.SelectedItem as PosCustomerOption;
        await viewModel.LoadCustomerHistoryAsync(customer);

        if (requestId != historyRequestId)
        {
            return;
        }
    }

    private void Select_Click(object sender, RoutedEventArgs e)
    {
        SelectCurrentCustomer();
    }

    private void SelectCurrentCustomer()
    {
        if (CustomersGrid.SelectedItem is not PosCustomerOption customer)
        {
            StatusText.Text = "Selecciona un cliente de la lista.";
            return;
        }

        SelectedCustomer = customer;
        DialogResult = true;
        Close();
    }

    private void WalkIn_Click(object sender, RoutedEventArgs e)
    {
        UseWalkInCustomer = true;
        DialogResult = true;
        Close();
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }
}
