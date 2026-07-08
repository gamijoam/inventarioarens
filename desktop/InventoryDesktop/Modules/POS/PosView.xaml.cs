using System.Net.Http;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Threading;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public partial class PosView : UserControl
{
    private readonly DispatcherTimer searchDebounceTimer;

    public PosView()
    {
        InitializeComponent();
        searchDebounceTimer = new DispatcherTimer
        {
            Interval = TimeSpan.FromMilliseconds(450),
        };
        searchDebounceTimer.Tick += SearchDebounceTimer_Tick;
        Loaded += (_, _) => SearchBox.Focus();
        PreviewKeyDown += PosView_PreviewKeyDown;
    }

    public event EventHandler? ExitRequested;

    private PosViewModel? ViewModel => DataContext as PosViewModel;

    private void Exit_Click(object sender, RoutedEventArgs e)
    {
        ExitRequested?.Invoke(this, EventArgs.Empty);
    }

    private async void Search_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            searchDebounceTimer.Stop();
            await ViewModel.SearchAsync();
            OpenProductSearchDialog();
        }
    }

    private void PosView_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.F2)
        {
            OpenProductSearchDialog();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F8)
        {
            OpenCustomerDialog();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F9)
        {
            OpenLastReceiptDialog();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F5)
        {
            _ = RefreshProductsAsync();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F12)
        {
            Pay_Click(sender, e);
            e.Handled = true;
            return;
        }

        if (e.Key == Key.Escape && Keyboard.FocusedElement == SearchBox)
        {
            SearchBox.Clear();
            if (ViewModel is not null)
            {
                ViewModel.StatusMessage = "Búsqueda limpia. Escanea o escribe para continuar.";
                ViewModel.IsStatusError = false;
            }

            e.Handled = true;
        }
    }

    private async void SearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter && ViewModel is not null)
        {
            searchDebounceTimer.Stop();
            await ViewModel.SearchAsync();
            await TryAddExactSearchMatchAsync();
            e.Handled = true;
        }
    }

    private void SearchBox_TextChanged(object sender, TextChangedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        string text = SearchBox.Text.Trim();
        searchDebounceTimer.Stop();
        if (text.Length < 2)
        {
            return;
        }

        searchDebounceTimer.Start();
    }

    private async void SearchDebounceTimer_Tick(object? sender, EventArgs e)
    {
        searchDebounceTimer.Stop();
        if (ViewModel is null || ViewModel.IsBusy)
        {
            return;
        }

        await ViewModel.SearchAsync();
    }

    private async void Product_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || sender is not FrameworkElement element || element.DataContext is not PosProductCard card)
        {
            return;
        }

        await AddCardToCartAsync(card);
    }

    private async Task AddCardToCartAsync(PosProductCard card, InventoryProductSerial? selectedSerial = null)
    {
        if (ViewModel is null)
        {
            return;
        }

        if (!ViewModel.HasStockAvailableForCart(card))
        {
            string message = ViewModel.BuildNoStockMessage(card);
            ShowPosAlert(message, "Stock no disponible");
            ViewModel.SetError(message);
            return;
        }

        if (card.Product.TrackingType == "serialized" && selectedSerial is null)
        {
            PosSerialSelectionWindow dialog = new(card, ViewModel)
            {
                Owner = Window.GetWindow(this),
            };

            bool? result = dialog.ShowDialog();
            if (result != true || dialog.SelectedSerial is null)
            {
                return;
            }

            await ViewModel.AddProductAsync(card, dialog.SelectedSerial);
            return;
        }

        await ViewModel.AddProductAsync(card, selectedSerial);
    }

    private async Task TryAddExactSearchMatchAsync()
    {
        if (ViewModel is null)
        {
            return;
        }

        try
        {
            PosProductCard? card = ViewModel.FindExactSearchMatch();
            if (card is null)
            {
                ExactSerialSearchMatch? serialMatch = await ViewModel.FindExactSerialSearchMatchAsync();
                if (serialMatch is null)
                {
                    return;
                }

                await AddCardToCartAsync(serialMatch.Card, serialMatch.Serial);
                SearchBox.Focus();
                SearchBox.SelectAll();
                return;
            }

            if (card.Product.TrackingType == "serialized")
            {
                InventoryProductSerial? exactSerial = await ViewModel.FindExactAvailableSerialAsync(card, ViewModel.SearchText);
                await AddCardToCartAsync(card, exactSerial);
            }
            else
            {
                await AddCardToCartAsync(card);
            }

            SearchBox.Focus();
            SearchBox.SelectAll();
        }
        catch (ApiException exception)
        {
            ShowPosAlert(exception.Message, "No se pudo agregar");
            ViewModel.SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            string message = "No se pudo conectar con la API para agregar por código.";
            ShowPosAlert(message, "Sin conexión");
            ViewModel.SetError(message);
        }
    }

    private async Task RefreshProductsAsync()
    {
        if (ViewModel is null)
        {
            return;
        }

        searchDebounceTimer.Stop();
        await ViewModel.SearchAsync();
        SearchBox.Focus();
        SearchBox.SelectAll();
    }

    private async void ReloadContext_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.LoadOperationalContextAsync(forceStaticRefresh: true);
        }
    }

    private async void OpenCashRegister_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            if (ViewModel.SelectedWarehouse is null)
            {
                string message = "Selecciona un almacén antes de abrir caja.";
                ShowPosAlert(message, "Falta almacén");
                ViewModel.SetError(message);
                return;
            }

            MessageBoxResult result = MessageBox.Show(
                Window.GetWindow(this),
                $"Se abrirá una caja para vender en:\n\n{ViewModel.SelectedWarehouse.WarehouseLabel}\n\nMonto inicial: USD 0.00\n\n¿Deseas continuar?",
                "Abrir caja",
                MessageBoxButton.YesNo,
                MessageBoxImage.Question);

            if (result != MessageBoxResult.Yes)
            {
                return;
            }

            await ViewModel.OpenOwnCashRegisterAsync();
        }
    }

    private void IncreaseItem_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null && sender is FrameworkElement element && element.DataContext is PosCartItem item)
        {
            ViewModel.Increase(item);
        }
    }

    private void DecreaseItem_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null && sender is FrameworkElement element && element.DataContext is PosCartItem item)
        {
            ViewModel.Decrease(item);
        }
    }

    private void RemoveItem_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null && sender is FrameworkElement element && element.DataContext is PosCartItem item)
        {
            ViewModel.RemoveItem(item);
        }
    }

    private void DiscountItem_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || sender is not FrameworkElement element || element.DataContext is not PosCartItem item)
        {
            return;
        }

        PosDiscountWindow dialog = new(item)
        {
            Owner = Window.GetWindow(this),
        };

        bool? result = dialog.ShowDialog();
        if (result != true)
        {
            return;
        }

        if (dialog.ShouldClearDiscount)
        {
            item.ClearDiscount();
            ViewModel.StatusMessage = "Descuento retirado.";
            ViewModel.IsStatusError = false;
            return;
        }

        item.ApplyDiscount(dialog.DiscountType, dialog.DiscountValue, dialog.DiscountReason);
        ViewModel.StatusMessage = "Descuento aplicado al producto.";
        ViewModel.IsStatusError = false;
    }

    private void ClearCart_Click(object sender, RoutedEventArgs e)
    {
        ViewModel?.ClearCart();
    }

    private void SelectCustomer_Click(object sender, RoutedEventArgs e)
    {
        OpenCustomerDialog();
    }

    private void OpenCustomerDialog()
    {
        if (ViewModel is null)
        {
            return;
        }

        PosCustomerSelectionWindow dialog = new(ViewModel)
        {
            Owner = Window.GetWindow(this),
        };

        bool? result = dialog.ShowDialog();
        if (result != true)
        {
            return;
        }

        if (dialog.UseWalkInCustomer)
        {
            ViewModel.ClearCustomer();
            return;
        }

        if (dialog.SelectedCustomer is not null)
        {
            ViewModel.SelectedCustomer = dialog.SelectedCustomer;
            ViewModel.StatusMessage = $"Cliente seleccionado: {dialog.SelectedCustomer.Name}.";
            ViewModel.IsStatusError = false;
        }
    }

    private void OpenProductSearch_Click(object sender, RoutedEventArgs e)
    {
        OpenProductSearchDialog();
    }

    private void OpenProductSearchDialog()
    {
        if (ViewModel is null)
        {
            return;
        }

        PosProductSearchWindow dialog = new(ViewModel)
        {
            Owner = Window.GetWindow(this),
        };

        dialog.ShowDialog();
        SearchBox.Focus();
        SearchBox.SelectAll();
    }

    private void ClearCustomer_Click(object sender, RoutedEventArgs e)
    {
        ViewModel?.ClearCustomer();
    }

    private async void Pay_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        if (ViewModel.SelectedWarehouse is null)
        {
            string message = "Selecciona un almacén antes de cobrar.";
            ShowPosAlert(message, "No se puede cobrar");
            ViewModel.SetError(message);
            return;
        }

        if (ViewModel.CartItems.Count == 0)
        {
            string message = "Agrega al menos un producto antes de cobrar.";
            ShowPosAlert(message, "Carrito vacío");
            ViewModel.SetError(message);
            return;
        }

        if (ViewModel.SelectedCashRegisterSession?.HasPhysicalRegister != true)
        {
            await ViewModel.LoadCashRegisterSessionsAsync();
            if (ViewModel.SelectedCashRegisterSession?.HasPhysicalRegister != true)
            {
                string message = "No tienes una caja fisica abierta asignada a tu usuario. Abre tu caja desde el modulo Caja antes de cobrar.";
                ShowPosAlert(message, "Caja requerida");
                ViewModel.SetError(message);
                return;
            }
        }

        if (ViewModel.PaymentMethods.Count == 0)
        {
            await ViewModel.LoadPaymentMethodsAsync();
        }

        PosPaymentWindow dialog = new(ViewModel)
        {
            Owner = Window.GetWindow(this),
        };

        dialog.ShowDialog();
        if (dialog.Receipt is not null)
        {
            ViewModel.StoreLastReceipt(dialog.Receipt);
        }
    }

    private void LastReceipt_Click(object sender, RoutedEventArgs e)
    {
        OpenLastReceiptDialog();
    }

    private void OpenLastReceiptDialog()
    {
        if (ViewModel?.LastReceipt is not PosReceiptSnapshot receipt)
        {
            string message = "No hay un recibo reciente para mostrar en esta sesión.";
            ShowPosAlert(message, "Sin recibo reciente", MessageBoxImage.Information);
            ViewModel?.SetError(message);
            return;
        }

        PosReceiptWindow dialog = new(receipt)
        {
            Owner = Window.GetWindow(this),
        };

        dialog.ShowDialog();
    }

    private void ShowPosAlert(string message, string title = "Atención", MessageBoxImage icon = MessageBoxImage.Warning)
    {
        MessageBox.Show(
            Window.GetWindow(this),
            message,
            title,
            MessageBoxButton.OK,
            icon);
    }

    private void PendingOrders_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null)
        {
            return;
        }

        PosPendingOrdersWindow dialog = new(ViewModel)
        {
            Owner = Window.GetWindow(this),
        };

        dialog.ShowDialog();
    }
}
