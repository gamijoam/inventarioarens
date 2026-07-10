using System.Windows;

namespace InventoryDesktop.Modules.InventoryTransfers;

public partial class InventoryTransferCancelWindow : Window
{
    private const int MinReasonLength = 5;
    private const int MaxReasonLength = 1000;

    public InventoryTransferCancelWindow(string guideNumber, string? documentNumber, string? routeLabel)
    {
        InitializeComponent();
        string displayGuide = string.IsNullOrWhiteSpace(guideNumber) ? documentNumber ?? "-" : guideNumber;
        TitleText.Text = $"Cancelar {displayGuide}";
        GuideText.Text = string.IsNullOrWhiteSpace(routeLabel)
            ? displayGuide
            : $"{displayGuide} - {routeLabel}";
        ReasonBox.Focus();
    }

    public string Reason => ReasonBox.Text.Trim();

    private void ReasonBox_TextChanged(object sender, System.Windows.Controls.TextChangedEventArgs e)
    {
        int length = ReasonBox.Text.Length;
        CounterText.Text = $"{length} / {MaxReasonLength} caracteres";
        bool valid = length >= MinReasonLength && length <= MaxReasonLength;
        ConfirmButton.IsEnabled = valid;
        if (length > MaxReasonLength)
        {
            CounterText.Foreground = System.Windows.Media.Brushes.IndianRed;
        }
        else if (valid)
        {
            CounterText.Foreground = System.Windows.Media.Brushes.MediumSeaGreen;
        }
        else
        {
            CounterText.Foreground = (System.Windows.Media.Brush)System.Windows.Application.Current.Resources["MutedBrush"];
        }
    }

    private void Confirm_Click(object sender, RoutedEventArgs e)
    {
        string reason = ReasonBox.Text.Trim();
        if (reason.Length < MinReasonLength || reason.Length > MaxReasonLength)
        {
            return;
        }

        DialogResult = true;
        Close();
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }
}
