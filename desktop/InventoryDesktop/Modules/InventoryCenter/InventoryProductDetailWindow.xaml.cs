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
        TryOpenWindow(() => new InventoryProductKardexWindow(detail, apiClient), "Kardex");
    }

    private void OpenEntry_Click(object sender, RoutedEventArgs e)
    {
        TryOpenWindow(() => new InventoryProductEntryWindow(detail, apiClient), "entrada");
    }

    private void OpenExit_Click(object sender, RoutedEventArgs e)
    {
        TryOpenWindow(() => new InventoryProductExitWindow(detail, apiClient), "salida");
    }

    private void TryOpenWindow(Func<Window> createWindow, string actionName)
    {
        try
        {
            Window window = createWindow();
            window.Owner = this;
            window.Show();
            window.Activate();
        }
        catch (Exception exception)
        {
            MessageBox.Show(
                $"No se pudo abrir la ventana de {actionName}.\n\n{exception.Message}",
                "Sistema de Inventario",
                MessageBoxButton.OK,
                MessageBoxImage.Error);
        }
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}
