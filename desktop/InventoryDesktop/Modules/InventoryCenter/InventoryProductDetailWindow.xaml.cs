using System.Windows;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductDetailWindow : Window
{
    public InventoryProductDetailWindow(InventoryProductDetailData detail)
    {
        InitializeComponent();
        DataContext = detail;
        Title = $"Detalle - {detail.Product.Name}";
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}
