using System.Windows;

namespace InventoryDesktop.Modules.InventoryCenter;

public partial class InventoryAlertsWindow : Window
{
    public InventoryAlertsWindow(IReadOnlyList<InventoryCenterAlert> alerts)
    {
        InitializeComponent();
        DataContext = new InventoryAlertsWindowModel(alerts);
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }
}

public sealed class InventoryAlertsWindowModel
{
    public InventoryAlertsWindowModel(IReadOnlyList<InventoryCenterAlert> alerts)
    {
        Alerts = alerts;
    }

    public IReadOnlyList<InventoryCenterAlert> Alerts { get; }

    public string CountLabel => Alerts.Count == 1
        ? "1 alerta"
        : $"{Alerts.Count} alertas";
}
