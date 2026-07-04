using System.Windows;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductDetailWindow : Window
{
    private readonly ApiClient apiClient;
    private readonly InventoryProductDetailData detail;

    public InventoryProductDetailWindow(InventoryProductDetailData detail, ApiClient apiClient)
    {
        this.detail = detail;
        this.apiClient = apiClient;

        InitializeComponent();
        DataContext = detail;
        Title = $"Detalle - {detail.Product.Name}";
    }

    private void OpenKardex_Click(object sender, RoutedEventArgs e)
    {
        InventoryProductKardexWindow window = new(detail, apiClient)
        {
            Owner = this
        };
        window.Show();
        window.Activate();
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}
