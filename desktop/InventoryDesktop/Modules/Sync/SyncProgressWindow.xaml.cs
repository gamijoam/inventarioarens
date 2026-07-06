using System.Windows;

namespace InventoryDesktop.Modules.Sync;

public partial class SyncProgressWindow : Window
{
    public SyncProgressWindow()
    {
        InitializeComponent();
    }

    public void MarkCompleted(string message)
    {
        Progress.IsIndeterminate = false;
        Progress.Value = 100;
        TitleText.Text = "Sincronizacion completada";
        StatusText.Text = message;
        CloseButton.Visibility = Visibility.Visible;
    }

    public void MarkPending(string message)
    {
        Progress.IsIndeterminate = false;
        Progress.Value = 0;
        TitleText.Text = "Sincronizacion pendiente";
        StatusText.Text = message;
        CloseButton.Visibility = Visibility.Visible;
    }

    public void MarkFailed(string message)
    {
        Progress.IsIndeterminate = false;
        Progress.Value = 0;
        TitleText.Text = "No se pudo sincronizar";
        StatusText.Text = message;
        CloseButton.Visibility = Visibility.Visible;
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}
