using System.Net.Http;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Controls.Primitives;
using System.Windows.Input;
using System.Windows.Threading;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public partial class PosView : UserControl
{
    private readonly DispatcherTimer searchDebounceTimer;
    private readonly DispatcherTimer searchFocusTimer;

    public PosView()
    {
        InitializeComponent();
        searchDebounceTimer = new DispatcherTimer
        {
            Interval = TimeSpan.FromMilliseconds(450),
        };
        searchFocusTimer = new DispatcherTimer
        {
            Interval = TimeSpan.FromMilliseconds(700),
        };
        searchDebounceTimer.Tick += SearchDebounceTimer_Tick;
        searchFocusTimer.Tick += SearchFocusTimer_Tick;
        Loaded += PosView_Loaded;
        Unloaded += PosView_Unloaded;
        PreviewKeyDown += PosView_PreviewKeyDown;
        PreviewTextInput += PosView_PreviewTextInput;
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
            FocusSearchBox(selectAll: true);
        }
    }

    private void PosView_Loaded(object sender, RoutedEventArgs e)
    {
        searchFocusTimer.Start();
        FocusSearchBox(selectAll: true);
    }

    private void PosView_Unloaded(object sender, RoutedEventArgs e)
    {
        searchFocusTimer.Stop();
        searchDebounceTimer.Stop();
    }

    private async void PosView_PreviewKeyDown(object sender, KeyEventArgs e)
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
            FocusSearchBox();
            return;
        }

        if (Keyboard.FocusedElement != SearchBox && !IsTextEntryElement(Keyboard.FocusedElement))
        {
            if (e.Key == Key.Enter && ViewModel is not null && !string.IsNullOrWhiteSpace(SearchBox.Text))
            {
                searchDebounceTimer.Stop();
                await ViewModel.SearchAsync();
                await TryAddExactSearchMatchAsync();
                e.Handled = true;
                FocusSearchBox(selectAll: true);
                return;
            }

            if (TryGetTextFromKey(e.Key, out string text))
            {
                SearchBox.Focus();
                Keyboard.Focus(SearchBox);
                InsertTextInSearchBox(text);
                e.Handled = true;
                return;
            }
        }
    }

    private void PosView_PreviewTextInput(object sender, TextCompositionEventArgs e)
    {
        if (string.IsNullOrEmpty(e.Text) || IsTextEntryElement(Keyboard.FocusedElement))
        {
            return;
        }

        SearchBox.Focus();
        Keyboard.Focus(SearchBox);
        InsertTextInSearchBox(e.Text);
        e.Handled = true;
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

    private void SearchBox_LostKeyboardFocus(object sender, KeyboardFocusChangedEventArgs e)
    {
        if (IsTextEntryElement(e.NewFocus) || e.NewFocus is ComboBoxItem)
        {
            return;
        }

        FocusSearchBox();
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
            FocusSearchBox(selectAll: true);
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
                FocusSearchBox(selectAll: true);
                return;
            }

            await ViewModel.AddProductAsync(card, dialog.SelectedSerial);
            FocusSearchBox(selectAll: true);
            return;
        }

        await ViewModel.AddProductAsync(card, selectedSerial);
        FocusSearchBox(selectAll: true);
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
                FocusSearchBox(selectAll: true);
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

            FocusSearchBox(selectAll: true);
        }
        catch (ApiException exception)
        {
            ShowPosAlert(exception.Message, "No se pudo agregar");
            ViewModel.SetError(exception.Message);
            FocusSearchBox(selectAll: true);
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
        FocusSearchBox(selectAll: true);
    }

    private async void ReloadContext_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.LoadOperationalContextAsync(forceStaticRefresh: true);
            FocusSearchBox(selectAll: true);
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
                FocusSearchBox(selectAll: true);
                return;
            }

            await ViewModel.OpenOwnCashRegisterAsync();
            FocusSearchBox(selectAll: true);
        }
    }

    private void IncreaseItem_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null && sender is FrameworkElement element && element.DataContext is PosCartItem item)
        {
            ViewModel.Increase(item);
            FocusSearchBox();
        }
    }

    private void DecreaseItem_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null && sender is FrameworkElement element && element.DataContext is PosCartItem item)
        {
            ViewModel.Decrease(item);
            FocusSearchBox();
        }
    }

    private void RemoveItem_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null && sender is FrameworkElement element && element.DataContext is PosCartItem item)
        {
            ViewModel.RemoveItem(item);
            FocusSearchBox();
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
            FocusSearchBox(selectAll: true);
            return;
        }

        if (dialog.ShouldClearDiscount)
        {
            item.ClearDiscount();
            ViewModel.StatusMessage = "Descuento retirado.";
            ViewModel.IsStatusError = false;
            FocusSearchBox(selectAll: true);
            return;
        }

        item.ApplyDiscount(dialog.DiscountType, dialog.DiscountValue, dialog.DiscountReason);
        ViewModel.StatusMessage = "Descuento aplicado al producto.";
        ViewModel.IsStatusError = false;
        FocusSearchBox(selectAll: true);
    }

    private void ClearCart_Click(object sender, RoutedEventArgs e)
    {
        ViewModel?.ClearCart();
        FocusSearchBox();
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
            FocusSearchBox(selectAll: true);
            return;
        }

        if (dialog.UseWalkInCustomer)
        {
            ViewModel.ClearCustomer();
            FocusSearchBox(selectAll: true);
            return;
        }

        if (dialog.SelectedCustomer is not null)
        {
            ViewModel.SelectedCustomer = dialog.SelectedCustomer;
            ViewModel.StatusMessage = $"Cliente seleccionado: {dialog.SelectedCustomer.Name}.";
            ViewModel.IsStatusError = false;
            FocusSearchBox(selectAll: true);
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
        FocusSearchBox(selectAll: true);
    }

    private void ClearCustomer_Click(object sender, RoutedEventArgs e)
    {
        ViewModel?.ClearCustomer();
        FocusSearchBox();
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

        FocusSearchBox(selectAll: true);
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
        FocusSearchBox(selectAll: true);
    }

    private void ShowPosAlert(string message, string title = "Atención", MessageBoxImage icon = MessageBoxImage.Warning)
    {
        MessageBox.Show(
            Window.GetWindow(this),
            message,
            title,
            MessageBoxButton.OK,
            icon);
        FocusSearchBox(selectAll: true);
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
        FocusSearchBox(selectAll: true);
    }

    private void PosContext_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        FocusSearchBox();
    }

    private void SearchFocusTimer_Tick(object? sender, EventArgs e)
    {
        if (!IsVisible || !IsKeyboardFocusWithin)
        {
            return;
        }

        if (Keyboard.FocusedElement == SearchBox || IsTextEntryElement(Keyboard.FocusedElement))
        {
            return;
        }

        FocusSearchBox();
    }

    private void InsertTextInSearchBox(string text)
    {
        int selectionStart = SearchBox.SelectionStart;
        int selectionLength = SearchBox.SelectionLength;
        string currentText = SearchBox.Text ?? string.Empty;

        if (selectionLength > 0)
        {
            currentText = currentText.Remove(selectionStart, selectionLength);
        }

        SearchBox.Text = currentText.Insert(selectionStart, text);
        SearchBox.CaretIndex = selectionStart + text.Length;
    }

    private static bool IsTextEntryElement(object? focusedElement)
    {
        return focusedElement is TextBoxBase or PasswordBox or ComboBox;
    }

    private static bool TryGetTextFromKey(Key key, out string text)
    {
        text = string.Empty;
        Key normalizedKey = key;

        if (normalizedKey >= Key.D0 && normalizedKey <= Key.D9)
        {
            text = ((int)normalizedKey - (int)Key.D0).ToString();
            return true;
        }

        if (normalizedKey >= Key.NumPad0 && normalizedKey <= Key.NumPad9)
        {
            text = ((int)normalizedKey - (int)Key.NumPad0).ToString();
            return true;
        }

        if (normalizedKey >= Key.A && normalizedKey <= Key.Z)
        {
            bool upper = Keyboard.IsKeyDown(Key.LeftShift) || Keyboard.IsKeyDown(Key.RightShift);
            string letter = normalizedKey.ToString();
            text = upper ? letter : letter.ToLowerInvariant();
            return true;
        }

        if (normalizedKey is Key.OemMinus or Key.Subtract)
        {
            text = "-";
            return true;
        }

        return false;
    }

    private void FocusSearchBox(bool selectAll = false)
    {
        Dispatcher.BeginInvoke(
            () =>
            {
                if (!IsVisible)
                {
                    return;
                }

                SearchBox.Focus();
                Keyboard.Focus(SearchBox);
                if (selectAll)
                {
                    SearchBox.SelectAll();
                }
            },
            DispatcherPriority.Input);
    }
}
