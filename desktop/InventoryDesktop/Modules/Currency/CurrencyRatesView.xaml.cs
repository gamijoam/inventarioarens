using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Api;

namespace InventoryDesktop.Modules.Currency;

public partial class CurrencyRatesView : UserControl
{
    private CurrencyRatesViewModel? viewModel;
    private Func<Task>? syncNow;

    public CurrencyRatesView()
    {
        InitializeComponent();
    }

    public void Configure(ApiClient apiClient, Func<Task>? syncNow = null)
    {
        viewModel = new CurrencyRatesViewModel(apiClient);
        this.syncNow = syncNow;
        DataContext = viewModel;
    }

    public async Task LoadAsync()
    {
        if (viewModel is not null)
        {
            await viewModel.LoadAsync();
        }
    }

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        await LoadAsync();
    }

    private async void SyncNow_Click(object sender, RoutedEventArgs e)
    {
        if (syncNow is not null)
        {
            await syncNow();
        }

        await LoadAsync();
    }
}
