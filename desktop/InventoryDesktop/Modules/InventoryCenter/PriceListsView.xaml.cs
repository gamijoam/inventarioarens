using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Globalization;
using System.Net.Http;
using System.Runtime.CompilerServices;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Media;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class PriceListsView : UserControl, INotifyPropertyChanged
{
    private ApiClient? apiClient;
    private PriceListOption? selectedPriceList;
    private string nameInput = "";
    private string codeInput = "";
    private string? descriptionInput;
    private string sortOrderInput = "0";
    private bool isDefaultInput;
    private bool isActiveInput = true;
    private bool isBusy;
    private bool isStatusError;
    private string statusMessage = "Carga las listas para comenzar.";

    public PriceListsView()
    {
        InitializeComponent();
        DataContext = this;
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    public ObservableCollection<PriceListOption> PriceLists { get; } = new();

    public PriceListOption? SelectedPriceList
    {
        get => selectedPriceList;
        set
        {
            if (SetProperty(ref selectedPriceList, value))
            {
                RaisePropertyChanged(nameof(FormTitle));
                RaisePropertyChanged(nameof(CanDeactivate));
            }
        }
    }

    public string FormTitle => SelectedPriceList is null ? "Nueva lista" : "Editar lista";

    public string NameInput
    {
        get => nameInput;
        set => SetProperty(ref nameInput, value);
    }

    public string CodeInput
    {
        get => codeInput;
        set => SetProperty(ref codeInput, value);
    }

    public string? DescriptionInput
    {
        get => descriptionInput;
        set => SetProperty(ref descriptionInput, value);
    }

    public string SortOrderInput
    {
        get => sortOrderInput;
        set => SetProperty(ref sortOrderInput, value);
    }

    public bool IsDefaultInput
    {
        get => isDefaultInput;
        set => SetProperty(ref isDefaultInput, value);
    }

    public bool IsActiveInput
    {
        get => isActiveInput;
        set => SetProperty(ref isActiveInput, value);
    }

    public bool IsBusy
    {
        get => isBusy;
        set => SetProperty(ref isBusy, value);
    }

    public bool CanDeactivate => SelectedPriceList is not null && SelectedPriceList.IsActive && !IsBusy;

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => isStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public void Configure(ApiClient client)
    {
        apiClient = client;
    }

    public async Task LoadAsync()
    {
        if (apiClient is null || IsBusy)
        {
            return;
        }

        IsBusy = true;
        SetStatus("Cargando listas de precio...", false);

        try
        {
            PriceListListResponse response = await apiClient.GetAsync<PriceListListResponse>("price-lists");
            PriceLists.Clear();
            foreach (PriceListOption item in response.Data)
            {
                PriceLists.Add(item);
            }

            SetStatus(PriceLists.Count == 0
                ? "No hay listas de precio registradas."
                : $"{PriceLists.Count} listas de precio cargadas.", false);
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudieron cargar las listas de precio.", true);
        }
        finally
        {
            IsBusy = false;
            RaisePropertyChanged(nameof(CanDeactivate));
        }
    }

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        await LoadAsync();
    }

    private void New_Click(object sender, RoutedEventArgs e)
    {
        ClearForm();
    }

    private void PriceListsGrid_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        if (SelectedPriceList is null)
        {
            return;
        }

        NameInput = SelectedPriceList.Name;
        CodeInput = SelectedPriceList.Code;
        DescriptionInput = SelectedPriceList.Description;
        SortOrderInput = SelectedPriceList.SortOrder.ToString(CultureInfo.CurrentCulture);
        IsDefaultInput = SelectedPriceList.IsDefault;
        IsActiveInput = SelectedPriceList.IsActive;
    }

    private async void Save_Click(object sender, RoutedEventArgs e)
    {
        if (apiClient is null || IsBusy || !TryBuildRequest(out PriceListSaveRequest? request))
        {
            return;
        }

        IsBusy = true;
        SetStatus(SelectedPriceList is null ? "Creando lista..." : "Guardando lista...", false);

        try
        {
            if (SelectedPriceList is null)
            {
                await apiClient.PostAsync<PriceListSaveRequest, PriceListResponse>("price-lists", request!);
            }
            else
            {
                await apiClient.PatchAsync<PriceListSaveRequest, PriceListResponse>($"price-lists/{SelectedPriceList.Id}", request!);
            }

            ClearForm();
            await LoadAsync();
            SetStatus("Lista de precio guardada correctamente.", false);
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo guardar la lista de precio.", true);
        }
        finally
        {
            IsBusy = false;
            RaisePropertyChanged(nameof(CanDeactivate));
        }
    }

    private async void Deactivate_Click(object sender, RoutedEventArgs e)
    {
        if (apiClient is null || SelectedPriceList is null || IsBusy)
        {
            return;
        }

        IsBusy = true;
        SetStatus("Desactivando lista...", false);

        try
        {
            await apiClient.DeleteAsync($"price-lists/{SelectedPriceList.Id}");
            ClearForm();
            await LoadAsync();
            SetStatus("Lista de precio desactivada correctamente.", false);
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            SetStatus(exception is ApiException ? exception.Message : "No se pudo desactivar la lista de precio.", true);
        }
        finally
        {
            IsBusy = false;
            RaisePropertyChanged(nameof(CanDeactivate));
        }
    }

    private bool TryBuildRequest(out PriceListSaveRequest? request)
    {
        request = null;

        if (string.IsNullOrWhiteSpace(NameInput))
        {
            SetStatus("El nombre de la lista es obligatorio.", true);
            return false;
        }

        if (string.IsNullOrWhiteSpace(CodeInput))
        {
            SetStatus("El código de la lista es obligatorio.", true);
            return false;
        }

        if (!int.TryParse(SortOrderInput, NumberStyles.Integer, CultureInfo.CurrentCulture, out int sortOrder) || sortOrder < 0)
        {
            SetStatus("El orden debe ser un número entero igual o mayor que cero.", true);
            return false;
        }

        request = new PriceListSaveRequest(
            NameInput.Trim(),
            CodeInput.Trim(),
            string.IsNullOrWhiteSpace(DescriptionInput) ? null : DescriptionInput.Trim(),
            IsDefaultInput,
            IsActiveInput,
            sortOrder);

        return true;
    }

    private void ClearForm()
    {
        SelectedPriceList = null;
        NameInput = "";
        CodeInput = "";
        DescriptionInput = null;
        SortOrderInput = "0";
        IsDefaultInput = false;
        IsActiveInput = true;
        SetStatus("Formulario listo para una nueva lista.", false);
    }

    private void SetStatus(string message, bool isError)
    {
        StatusMessage = message;
        isStatusError = isError;
        RaisePropertyChanged(nameof(StatusBrush));
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
