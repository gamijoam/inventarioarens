using System.Windows;
using System.Windows.Input;

namespace InventoryDesktop.Modules.POS;

public partial class PosReceiptWindow : Window
{
    public PosReceiptWindow(PosReceiptSnapshot receipt)
    {
        InitializeComponent();
        LoadReceipt(receipt);
        PreviewKeyDown += PosReceiptWindow_PreviewKeyDown;
    }

    private void LoadReceipt(PosReceiptSnapshot receipt)
    {
        OrderBadgeText.Text = receipt.OrderLabel;
        ReceiptContextText.Text = $"{receipt.StatusLabel} · {receipt.CustomerName}";
        TotalUsdText.Text = receipt.TotalUsdLabel;
        TotalVesText.Text = receipt.TotalVesLabel;
        PaidText.Text = receipt.PaidLabel;
        ChangeText.Text = receipt.ChangeLabel;
        CustomerText.Text = $"Cliente: {receipt.CustomerName}";
        PriceListText.Text = $"Lista: {receipt.PriceListName}";
        CashRegisterText.Text = $"Caja: {receipt.CashRegisterLabel}";
        ItemsGrid.ItemsSource = receipt.Items;
        PaymentsGrid.ItemsSource = receipt.Payments;
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private void PosReceiptWindow_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key is Key.Enter or Key.Escape)
        {
            Close();
            e.Handled = true;
        }
    }
}
