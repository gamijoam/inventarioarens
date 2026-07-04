using System.Windows;
using System.Net.Http;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryProductDetailWindow : Window
{
    private readonly ApiClient apiClient;
    private InventoryProductDetailData detail;

    public event EventHandler? ProductChanged;

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
        try
        {
            InventoryProductEntryWindow window = new(detail, apiClient)
            {
                Owner = this
            };
            window.Saved += MovementWindow_Saved;
            window.Show();
            window.Activate();
        }
        catch (Exception exception)
        {
            ShowOpenError("entrada", exception);
        }
    }

    private void OpenExit_Click(object sender, RoutedEventArgs e)
    {
        try
        {
            InventoryProductExitWindow window = new(detail, apiClient)
            {
                Owner = this
            };
            window.Saved += MovementWindow_Saved;
            window.Show();
            window.Activate();
        }
        catch (Exception exception)
        {
            ShowOpenError("salida", exception);
        }
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
            ShowOpenError(actionName, exception);
        }
    }

    private async void MovementWindow_Saved(object? sender, EventArgs e)
    {
        await RefreshDetailAsync();
        ProductChanged?.Invoke(this, EventArgs.Empty);
    }

    private async Task RefreshDetailAsync()
    {
        try
        {
            InventoryProductDetailResponse response = await apiClient.GetAsync<InventoryProductDetailResponse>(
                $"inventory-center/products/{detail.Product.Id}");

            detail = response.Data;
            DataContext = detail;
            Title = $"Detalle - {detail.Product.Name}";
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            MessageBox.Show(
                $"El movimiento se guardó, pero no se pudo actualizar el detalle automáticamente.\n\n{exception.Message}",
                "Sistema de Inventario",
                MessageBoxButton.OK,
                MessageBoxImage.Warning);
        }
    }

    private static void ShowOpenError(string actionName, Exception exception)
    {
        MessageBox.Show(
            $"No se pudo abrir la ventana de {actionName}.\n\n{exception.Message}",
            "Sistema de Inventario",
            MessageBoxButton.OK,
            MessageBoxImage.Error);
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}
