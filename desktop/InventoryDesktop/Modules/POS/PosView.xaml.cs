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

    private PosViewModel? ViewModel => DataContext as PosViewModel;

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

        await ViewModel.AddProductAsync(card);
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
}
