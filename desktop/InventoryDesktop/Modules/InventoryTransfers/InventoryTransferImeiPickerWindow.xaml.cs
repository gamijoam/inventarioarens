using System.Collections.ObjectModel;
using System.ComponentModel;
using System.Net.Http;
using System.Windows;
using System.Windows.Input;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.InventoryTransfers;

public partial class InventoryTransferImeiPickerWindow : Window
{
    private readonly ApiClient apiClient;
    private readonly long productId;
    private readonly long warehouseId;
    private readonly string statusFilter;
    private readonly string actionLabel;
    private readonly int requiredCount;
    private readonly HashSet<long> allowedIds;
    private readonly ObservableCollection<PickerRow> rows = new();
    private readonly List<long> initialSelection = new();

    public InventoryTransferImeiPickerWindow(
        ApiClient apiClient,
        long productId,
        long warehouseId,
        string statusFilter,
        string productName,
        string actionLabel,
        int requiredCount,
        IEnumerable<long> allowedIds,
        IEnumerable<long> initialSelection)
    {
        InitializeComponent();
        this.apiClient = apiClient;
        this.productId = productId;
        this.warehouseId = warehouseId;
        this.statusFilter = statusFilter;
        this.actionLabel = actionLabel;
        this.requiredCount = Math.Max(requiredCount, 0);
        this.allowedIds = allowedIds is null
            ? new HashSet<long>()
            : new HashSet<long>(allowedIds);
        if (initialSelection is not null)
        {
            foreach (long id in initialSelection)
            {
                this.initialSelection.Add(id);
            }
        }

        TitleText.Text = $"IMEI / serial - {productName}";
        SubtitleText.Text = $"Tilda exactamente {this.requiredCount} IMEI(s) para {actionLabel.ToLowerInvariant()}.";
        ItemsList.ItemsSource = rows;
        Loaded += OnLoadedAsync;
    }

    public IReadOnlyList<long> SelectedIds { get; private set; } = Array.Empty<long>();

    private async void OnLoadedAsync(object sender, RoutedEventArgs e)
    {
        Loaded -= OnLoadedAsync;
        await LoadAsync();
    }

    private async Task LoadAsync(string? search = null)
    {
        StatusText.Text = "Cargando seriales disponibles...";
        ConfirmButton.IsEnabled = false;

        try
        {
            List<string> query = new();
            query.Add($"status={Uri.EscapeDataString(statusFilter)}");
            query.Add("limit=100");
            if (warehouseId > 0)
            {
                query.Add($"warehouse_id={warehouseId}");
            }

            if (!string.IsNullOrWhiteSpace(search))
            {
                query.Add($"search={Uri.EscapeDataString(search)}");
            }

            string queryString = string.Join("&", query);
            InventoryTransferImeiSearchResponse response = await apiClient
                .GetAsync<InventoryTransferImeiSearchResponse>(
                    $"inventory-center/products/{productId}/serials?{queryString}");

            IReadOnlyList<InventoryTransferImeiOption> data = response.Data?.Data ?? Array.Empty<InventoryTransferImeiOption>();

            // Solo interesan los IDs que aplican al pool de este line (ProductUnitIds o PreparedUnitIds).
            // El backend devuelve los disponibles del almacen; intersectamos con allowedIds.
            rows.Clear();
            foreach (InventoryTransferImeiOption option in data
                .Where(option => allowedIds.Count == 0 || allowedIds.Contains(option.Id))
                .OrderBy(option => option.SerialNumber, StringComparer.OrdinalIgnoreCase))
            {
                PickerRow row = new(option, initialSelection.Contains(option.Id), OnRowSelectionChanged);
                rows.Add(row);
            }

            UpdateCounter();
            StatusText.Text = rows.Count == 0
                ? "No hay seriales disponibles para este producto en este almacen."
                : $"{rows.Count} serial(es) disponible(s). Tilda {requiredCount}.";
        }
        catch (Exception exception) when (exception is ApiException or HttpRequestException or TaskCanceledException)
        {
            rows.Clear();
            StatusText.Text = exception is ApiException
                ? exception.Message
                : "No se pudo cargar la lista de IMEIs.";
        }
    }

    private void OnRowSelectionChanged(PickerRow changedRow)
    {
        int selected = rows.Count(row => row.IsSelected);
        if (requiredCount > 0 && selected > requiredCount && changedRow.IsSelected)
        {
            // El operador intento tildar uno de mas, lo destildamos.
            changedRow.IsSelected = false;
            StatusText.Text = $"Solo puedes tildar hasta {requiredCount} IMEI(s).";
            return;
        }

        UpdateCounter();
    }

    private void UpdateCounter()
    {
        int selected = rows.Count(row => row.IsSelected);
        string text = requiredCount > 0
            ? $"{selected} de {requiredCount} seleccionados"
            : $"{selected} seleccionados";
        CounterText.Text = text;
        ConfirmButton.IsEnabled = requiredCount == 0
            ? true
            : selected == requiredCount;
    }

    private async void SearchBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            e.Handled = true;
            await LoadAsync(SearchBox.Text.Trim());
        }
    }

    private async void Search_Click(object sender, RoutedEventArgs e)
    {
        await LoadAsync(SearchBox.Text.Trim());
    }

    private void AutoFill_Click(object sender, RoutedEventArgs e)
    {
        if (requiredCount <= 0)
        {
            return;
        }

        int toSelect = requiredCount;
        foreach (PickerRow row in rows)
        {
            row.IsSelected = toSelect > 0;
            toSelect--;
        }

        UpdateCounter();
    }

    private void Clear_Click(object sender, RoutedEventArgs e)
    {
        foreach (PickerRow row in rows)
        {
            row.IsSelected = false;
        }

        UpdateCounter();
    }

    private void Confirm_Click(object sender, RoutedEventArgs e)
    {
        if (requiredCount > 0)
        {
            int selected = rows.Count(row => row.IsSelected);
            if (selected != requiredCount)
            {
                StatusText.Text = $"Debes tildar exactamente {requiredCount} IMEI(s).";
                return;
            }
        }

        SelectedIds = rows.Where(row => row.IsSelected).Select(row => row.Option.Id).ToList();
        DialogResult = true;
        Close();
    }

    private sealed class PickerRow : INotifyPropertyChanged
    {
        private readonly Action<PickerRow> onChanged;
        private bool isSelected;

        public PickerRow(InventoryTransferImeiOption option, bool initialSelected, Action<PickerRow> onChanged)
        {
            Option = option;
            isSelected = initialSelected;
            this.onChanged = onChanged;
        }

        public InventoryTransferImeiOption Option { get; }

        public string DisplayLabel => Option.DisplayLabel;

        public string MetaLabel => Option.MetaLabel;

        public bool IsSelected
        {
            get => isSelected;
            set
            {
                if (isSelected == value)
                {
                    return;
                }

                isSelected = value;
                PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(nameof(IsSelected)));
                onChanged(this);
            }
        }

        public event PropertyChangedEventHandler? PropertyChanged;
    }
}
