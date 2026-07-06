using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Security;

namespace InventoryDesktop.Modules.Sync;

public partial class SyncWorkerView : UserControl
{
    private readonly SyncWorkerViewModel viewModel = new();

    public SyncWorkerView()
    {
        InitializeComponent();
        DataContext = viewModel;
    }

    public void Configure(DesktopSession session)
    {
        viewModel.Configure(session);
    }

    public async Task LoadAsync()
    {
        await viewModel.RefreshAsync();
    }

    public async Task RefreshStatusAsync()
    {
        await viewModel.RefreshAsync();
    }

    public async Task RunOnceAsync()
    {
        await viewModel.RunOnceAsync();
    }

    public string Status => viewModel.Status;

    public string StatusDetail => viewModel.StatusDetail;

    public string Message => viewModel.Message;

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.RefreshAsync();
    }

    private async void Start_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.StartAsync();
    }

    private async void Stop_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.StopAsync();
    }

    private async void RunOnce_Click(object sender, RoutedEventArgs e)
    {
        await viewModel.RunOnceAsync();
    }

    private void SaveConfiguration_Click(object sender, RoutedEventArgs e)
    {
        viewModel.SaveConfiguration();
    }

    private void TokenBox_PasswordChanged(object sender, RoutedEventArgs e)
    {
        viewModel.Token = TokenBox.Password;
    }
}
