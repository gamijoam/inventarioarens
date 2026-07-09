using System.Collections.ObjectModel;
using System.Net.Http;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.ViewModels;

namespace InventoryDesktop.Modules.Currency;

public sealed class CurrencyRatesViewModel : ViewModelBase
{
    private readonly ApiClient apiClient;
    private bool isBusy;
    private bool isStatusError;
    private string statusMessage = "Carga las tasas vigentes para revisar operacion.";
    private string lastLoadedLabel = "Sin consultar";

    public CurrencyRatesViewModel(ApiClient apiClient)
    {
        this.apiClient = apiClient;
    }

    public ObservableCollection<CurrencyRateRow> Rates { get; } = new();

    public bool IsBusy
    {
        get => isBusy;
        set
        {
            if (SetProperty(ref isBusy, value))
            {
                RaisePropertyChanged(nameof(CanRefresh));
                RaisePropertyChanged(nameof(HasNoRates));
            }
        }
    }

    public bool CanRefresh => !IsBusy;

    public bool HasNoRates => !IsBusy && Rates.Count == 0;

    public string StatusMessage
    {
        get => statusMessage;
        set => SetProperty(ref statusMessage, value);
    }

    public Brush StatusBrush => isStatusError
        ? new SolidColorBrush(Color.FromRgb(217, 54, 92))
        : new SolidColorBrush(Color.FromRgb(100, 113, 140));

    public string CountLabel => $"{Rates.Count} tipo(s)";

    public string ActiveCountLabel => Rates.Count(row => row.IsActiveType).ToString("N0");

    public string MissingCountLabel => Rates.Count(row => row.NeedsAttention).ToString("N0");

    public string DefaultRateLabel => Rates.FirstOrDefault(row => row.IsDefault)?.CurrentRateLabel ?? "Sin predeterminada";

    public string LastLoadedLabel
    {
        get => lastLoadedLabel;
        set => SetProperty(ref lastLoadedLabel, value);
    }

    public async Task LoadAsync()
    {
        if (IsBusy)
        {
            return;
        }

        IsBusy = true;
        SetStatus("Consultando tasas...", false);

        try
        {
            Task<ExchangeRateTypeListResponse> typeTask = apiClient.GetAsync<ExchangeRateTypeListResponse>("currency/rate-types");
            Task<ExchangeRateCurrentResponse> currentTask = apiClient.GetAsync<ExchangeRateCurrentResponse>("currency/rates/current");
            await Task.WhenAll(typeTask, currentTask);

            IReadOnlyList<ExchangeRateTypeItem> types = typeTask.Result.Data;
            IReadOnlyDictionary<long, ExchangeRateItem> currentRates = currentTask.Result.Data
                .GroupBy(rate => rate.ExchangeRateTypeId)
                .ToDictionary(group => group.Key, group => group.OrderByDescending(rate => rate.EffectiveAt).First());

            Rates.Clear();
            foreach (ExchangeRateTypeItem type in types.OrderByDescending(type => type.IsDefault).ThenBy(type => type.Name))
            {
                currentRates.TryGetValue(type.Id, out ExchangeRateItem? currentRate);
                Rates.Add(new CurrencyRateRow(type, currentRate));
            }

            LastLoadedLabel = DateTime.Now.ToString("dd/MM/yyyy h:mm tt");
            RaiseSummaryProperties();

            SetStatus(
                Rates.Count == 0
                    ? "No hay tipos de tasa configurados para esta empresa."
                    : "Tasas cargadas. Si falta una tasa vigente, sincroniza o revisa el panel web.",
                false);
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            Rates.Clear();
            RaiseSummaryProperties();
            SetStatus(exception is ApiException ? exception.Message : "No se pudieron cargar las tasas.", true);
        }
        finally
        {
            IsBusy = false;
        }
    }

    private void SetStatus(string message, bool isError)
    {
        isStatusError = isError;
        StatusMessage = message;
        RaisePropertyChanged(nameof(StatusBrush));
    }

    private void RaiseSummaryProperties()
    {
        RaisePropertyChanged(nameof(CountLabel));
        RaisePropertyChanged(nameof(ActiveCountLabel));
        RaisePropertyChanged(nameof(MissingCountLabel));
        RaisePropertyChanged(nameof(DefaultRateLabel));
        RaisePropertyChanged(nameof(HasNoRates));
    }
}

public sealed class CurrencyRateRow
{
    public CurrencyRateRow(ExchangeRateTypeItem type, ExchangeRateItem? currentRate)
    {
        Type = type;
        CurrentRate = currentRate;
    }

    public ExchangeRateTypeItem Type { get; }

    public ExchangeRateItem? CurrentRate { get; }

    public string Name => Type.Name;

    public string Code => Type.Code;

    public bool IsDefault => Type.IsDefault;

    public bool IsActiveType => Type.IsActive;

    public bool NeedsAttention => Type.IsActive && CurrentRate is null;

    public string DefaultLabel => Type.DefaultLabel;

    public string TypeStatusLabel => Type.StatusLabel;

    public string PairLabel => CurrentRate?.PairLabel ?? "USD/VES";

    public string CurrentRateLabel => CurrentRate?.RateLabel ?? "Sin tasa vigente";

    public string EffectiveLabel => CurrentRate?.EffectiveLabel ?? "Sin fecha";

    public string SourceLabel => string.IsNullOrWhiteSpace(CurrentRate?.Source) ? "Sin fuente" : CurrentRate!.Source!;

    public string OperationalStatusLabel => NeedsAttention
        ? "Requiere tasa"
        : Type.IsActive
            ? "Lista para operar"
            : "Inactiva";
}
