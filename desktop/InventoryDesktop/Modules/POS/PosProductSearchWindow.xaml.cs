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
            viewModel.SetError("Selecciona un producto para agregar.");
            return;
        }

        try
        {
            if (!viewModel.HasStockAvailableForCart(card))
            {
                viewModel.SetError(viewModel.BuildNoStockMessage(card));
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
            viewModel.SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            viewModel.SetError("No se pudo conectar con la API para agregar el producto.");
        }
    }
}
