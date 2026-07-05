using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Input;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public partial class PosSerialSelectionWindow : Window, INotifyPropertyChanged
{
    private readonly PosProductCard card;
    private readonly PosViewModel viewModel;
    private string searchText = "";
    private string statusMessage = "Cargando IMEI/seriales disponibles...";
    private bool isStatusError;
    private InventoryProductSerial? selectedSerial;

    public PosSerialSelectionWindow(PosProductCard card, PosViewModel viewModel)
    {
        this.card = card;
        this.viewModel = viewModel;
        ProductName = card.Product.Name;
        ProductSku = card.Product.Sku;
        ContextLabel = viewModel.SelectedWarehouse is null
            ? "Selecciona un almacén en el POS antes de continuar."
            : $"Almacén de salida: {viewModel.SelectedWarehouse.WarehouseLabel}";

        InitializeComponent();
        DataContext = this;
        Loaded += async (_, _) =>
        {
            SearchBox.Focus();
            SearchBox.SelectAll();
            await LoadSerialsAsync();
        };
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public string ProductName { get; }

    public string ProductSku { get; }

    public string ContextLabel { get; }

    public ObservableCollection<InventoryProductSerial> Serials { get; } = new();

    public InventoryProductSerial? SelectedSerial
    {
        get => selectedSerial;
        set => SetProperty(ref selectedSerial, value);
    }

    public string SearchText
    {
        get => searchText;
        set => SetProperty(ref searchText, value);
    }

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => isStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    private async Task LoadSerialsAsync()
    {
        try
        {
            isStatusError = false;
            RaisePropertyChanged(nameof(StatusBrush));
            StatusMessage = "Cargando IMEI/seriales disponibles...";

            IReadOnlyList<InventoryProductSerial> serials = await viewModel.LoadAvailableSerialsAsync(card.Product.Id, SearchText);
            Serials.Clear();
            foreach (InventoryProductSerial serial in serials)
            {
                Serials.Add(serial);
            }

            SelectedSerial = Serials.FirstOrDefault();
            StatusMessage = Serials.Count == 0
                ? "No hay IMEI/seriales disponibles para este producto en el almacén seleccionado."
                : $"{Serials.Count} IMEI/seriales disponibles.";
        }
        catch (ApiException exception)
        {
            Fail(exception.Message);
        }
        catch (HttpRequestException)
        {
            Fail("No se pudo conectar con la API para cargar IMEI/seriales.");
        }
    }

    private async void Search_Click(object sender, RoutedEventArgs e)
    {
        await LoadSerialsAsync();
    }

    private async void SearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            if (Serials.Count == 1 && SelectedSerial is not null)
            {
                UseSelectedSerial();
                return;
            }

            await LoadSerialsAsync();
        }
    }

    private void SerialsList_MouseDoubleClick(object sender, MouseButtonEventArgs e)
    {
        UseSelectedSerial();
    }

    private void Use_Click(object sender, RoutedEventArgs e)
    {
        UseSelectedSerial();
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
    }

    private void UseSelectedSerial()
    {
        if (SelectedSerial is null)
        {
            Fail("Selecciona un IMEI/serial disponible.");
            return;
        }

        DialogResult = true;
    }

    private void Fail(string message)
    {
        isStatusError = true;
        RaisePropertyChanged(nameof(StatusBrush));
        StatusMessage = message;
    }

    private void RaisePropertyChanged([CallerMemberName] string? propertyName = null)
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
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
}
