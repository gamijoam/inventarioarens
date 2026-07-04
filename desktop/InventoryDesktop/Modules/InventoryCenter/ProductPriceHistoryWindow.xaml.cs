using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Media;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class ProductPriceHistoryWindow : Window, INotifyPropertyChanged
{
    private readonly long productId;
    private readonly ApiClient apiClient;
    private string statusMessage = "Cargando historial de precios...";
    private bool isStatusError;

    public ProductPriceHistoryWindow(long productId, string productName, ApiClient apiClient)
    {
        this.productId = productId;
        ProductName = productName;
        this.apiClient = apiClient;

        InitializeComponent();
        DataContext = this;
        Title = $"Historial de precios - {productName}";
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public string ProductName { get; }

    public ObservableCollection<ProductPriceHistoryItem> Rows { get; } = new();

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public bool IsStatusError
    {
        get => isStatusError;
        set
        {
            if (SetProperty(ref isStatusError, value))
            {
                RaisePropertyChanged(nameof(StatusBrush));
            }
        }
    }

    public Brush StatusBrush => IsStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    private async void Window_Loaded(object sender, RoutedEventArgs e)
    {
        await LoadHistoryAsync();
    }

    private async void Reload_Click(object sender, RoutedEventArgs e)
    {
        await LoadHistoryAsync();
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private async Task LoadHistoryAsync()
    {
        try
        {
            IsStatusError = false;
            StatusMessage = "Cargando historial de precios...";

            ProductPriceHistoryResponse response = await apiClient.GetAsync<ProductPriceHistoryResponse>($"products/{productId}/price-history");

            Rows.Clear();
            foreach (ProductPriceHistoryItem item in response.Data)
            {
                Rows.Add(item);
            }

            StatusMessage = Rows.Count == 0
                ? "Este producto todavía no tiene cambios de precio registrados."
                : $"{Rows.Count} cambios de precio encontrados.";
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (HttpRequestException)
        {
            SetError("No se pudo conectar con la API para cargar el historial.");
        }
        catch (TaskCanceledException)
        {
            SetError("La carga del historial tardó demasiado. Intenta nuevamente.");
        }
    }

    private void SetError(string message)
    {
        IsStatusError = true;
        StatusMessage = message;
    }

    private bool SetProperty<T>(ref T field, T value, [CallerMemberName] string? propertyName = null)
    {
        if (EqualityComparer<T>.Default.Equals(field, value))
        {
            return false;
        }

        field = value;
        RaisePropertyChanged(propertyName);
        return true;
    }

    private void RaisePropertyChanged([CallerMemberName] string? propertyName = null)
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
    }
}
