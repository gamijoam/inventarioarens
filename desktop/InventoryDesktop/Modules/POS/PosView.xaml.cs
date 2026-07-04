using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;

namespace InventoryDesktop.Modules.POS;

public partial class PosView : UserControl
{
    public PosView()
    {
        InitializeComponent();
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
            await ViewModel.SearchAsync();
        }
    }

    private async void SearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter && ViewModel is not null)
        {
            await ViewModel.SearchAsync();
        }
    }

    private async void Product_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is null || sender is not FrameworkElement element || element.DataContext is not PosProductCard card)
        {
            return;
        }

        if (card.Product.TrackingType == "serialized")
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

        await ViewModel.AddProductAsync(card);
    }

    private async void ReloadContext_Click(object sender, RoutedEventArgs e)
    {
        if (ViewModel is not null)
        {
            await ViewModel.LoadOperationalContextAsync();
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

    private void ClearCart_Click(object sender, RoutedEventArgs e)
    {
        ViewModel?.ClearCart();
    }

    private void SelectCustomer_Click(object sender, RoutedEventArgs e)
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
        }
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

        if (ViewModel.PaymentMethods.Count == 0)
        {
            await ViewModel.LoadPaymentMethodsAsync();
        }

        PosPaymentWindow dialog = new(ViewModel)
        {
            Owner = Window.GetWindow(this),
        };

        dialog.ShowDialog();
    }
}
