using System.Net.Http;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public partial class PosProductSearchWindow : Window
{
    private readonly PosViewModel viewModel;

    public PosProductSearchWindow(PosViewModel viewModel)
    {
        InitializeComponent();
        this.viewModel = viewModel;
        DataContext = viewModel;
        Loaded += (_, _) =>
        {
            ProductSearchBox.Focus();
            ProductSearchBox.SelectAll();
        };
        PreviewKeyDown += PosProductSearchWindow_PreviewKeyDown;
    }

    public bool ProductWasAdded { get; private set; }

    private async void Search_Click(object sender, RoutedEventArgs e)
    {
        await SearchAsync();
    }

    private async void ProductSearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            await SearchAsync();
            e.Handled = true;
        }
    }

    private async void ProductsList_MouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        await AddSelectedProductAsync();
    }

    private async void PosProductSearchWindow_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Escape)
        {
            DialogResult = ProductWasAdded;
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F2)
        {
            ProductSearchBox.Focus();
            ProductSearchBox.SelectAll();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.Enter && Keyboard.FocusedElement != ProductSearchBox)
        {
            await AddSelectedProductAsync();
            e.Handled = true;
        }
    }

    private async void AddSelected_Click(object sender, RoutedEventArgs e)
    {
        await AddSelectedProductAsync();
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = ProductWasAdded;
    }

    private async Task SearchAsync()
    {
        await viewModel.SearchAsync();
        ProductsList.SelectedIndex = viewModel.Products.Count > 0 ? 0 : -1;
    }

    private async Task AddSelectedProductAsync()
    {
        if (ProductsList.SelectedItem is not PosProductCard card)
        {
            const string message = "Selecciona un producto para agregar.";
            ShowAlert(message, "Producto requerido");
            viewModel.SetError(message);
            return;
        }

        try
        {
            if (!viewModel.HasStockAvailableForCart(card))
            {
                string message = viewModel.BuildNoStockMessage(card);
                ShowAlert(message, "Stock no disponible");
                viewModel.SetError(message);
                return;
            }

            if (card.Product.TrackingType == "serialized")
            {
                PosSerialSelectionWindow serialDialog = new(card, viewModel)
                {
                    Owner = this,
                };

                bool? serialResult = serialDialog.ShowDialog();
                if (serialResult != true || serialDialog.SelectedSerial is not InventoryProductSerial selectedSerial)
                {
                    return;
                }

                await viewModel.AddProductAsync(card, selectedSerial);
            }
            else
            {
                await viewModel.AddProductAsync(card);
            }

            ProductWasAdded = true;
            ProductSearchBox.Focus();
            ProductSearchBox.SelectAll();
        }
        catch (ApiException exception)
        {
            ShowAlert(exception.Message, "No se pudo agregar");
            viewModel.SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            const string message = "No se pudo conectar con la API para agregar el producto.";
            ShowAlert(message, "Sin conexión");
            viewModel.SetError(message);
        }
    }

    private void ShowAlert(string message, string title = "Atención", MessageBoxImage icon = MessageBoxImage.Warning)
    {
        MessageBox.Show(this, message, title, MessageBoxButton.OK, icon);
    }
}
