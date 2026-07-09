using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public partial class PosPendingOrdersWindow : Window
{
    private readonly PosViewModel viewModel;

    public PosPendingOrdersWindow(PosViewModel viewModel)
    {
        InitializeComponent();
        this.viewModel = viewModel;
        OrdersGrid.ItemsSource = viewModel.PendingOrders;
        LoadPaymentStatuses();
        Loaded += async (_, _) => await RefreshAsync();
    }

    private PosOrderSummary? SelectedOrder => OrdersGrid.SelectedItem as PosOrderSummary;

    private async Task RefreshAsync()
    {
        ClearError();
        await EnsurePaymentMethodsAsync();
        await viewModel.LoadPendingOrdersAsync();
        OrdersSummaryText.Text = viewModel.PendingOrders.Count == 0
            ? "No hay ordenes pendientes para tu caja."
            : $"{viewModel.PendingOrders.Count} orden(es) pendiente(s) de tu caja.";
        OrdersGrid.SelectedIndex = viewModel.PendingOrders.Count > 0 ? 0 : -1;
        RefreshSelectedOrder();
        if (viewModel.PendingOrders.Count == 0)
        {
            SetInfo("No hay ordenes pendientes para tu caja. Si acabas de cobrar una, ya quedo cerrada.");
        }
    }

    private async Task EnsurePaymentMethodsAsync()
    {
        if (viewModel.PaymentMethods.Count == 0)
        {
            await viewModel.LoadPaymentMethodsAsync();
        }

        List<PaymentMethodChoice> choices = viewModel.PaymentMethods
            .Where(method => method.IsActive)
            .OrderBy(method => method.SortOrder)
            .ThenBy(method => method.Name)
            .Select(PaymentMethodChoice.FromConfigured)
            .ToList();

        if (choices.Count == 0)
        {
            choices.Add(new PaymentMethodChoice(null, "Efectivo USD", "cash", "USD", false, 0));
            choices.Add(new PaymentMethodChoice(null, "Transferencia Bs", "transfer", "VES", true, 1));
        }

        PaymentMethodBox.ItemsSource = choices;
        PaymentMethodBox.SelectedIndex = choices.Count > 0 ? 0 : -1;
        RefreshCurrencyOptions();
    }

    private void LoadPaymentStatuses()
    {
        PaymentStatusBox.ItemsSource = new List<PaymentStatusChoice>
        {
            new("captured", "Capturado"),
            new("pending", "Pendiente"),
        };
        PaymentStatusBox.SelectedIndex = 0;
    }

    private void OrdersGrid_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        RefreshSelectedOrder();
    }

    private void PaymentMethodBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        RefreshCurrencyOptions();
    }

    private void RefreshCurrencyOptions()
    {
        CurrencyBox.Items.Clear();
        if (PaymentMethodBox.SelectedItem is not PaymentMethodChoice method)
        {
            return;
        }

        if (method.CurrencyMode.Equals("flexible", StringComparison.OrdinalIgnoreCase))
        {
            CurrencyBox.Items.Add("USD");
            CurrencyBox.Items.Add("VES");
            CurrencyBox.SelectedIndex = 0;
        }
        else
        {
            CurrencyBox.Items.Add(method.CurrencyMode.ToUpperInvariant());
            CurrencyBox.SelectedIndex = 0;
        }

        ReferenceLabel.Text = method.RequiresReference ? "Referencia (obligatoria)" : "Referencia";
    }

    private void RefreshSelectedOrder()
    {
        PosOrderSummary? order = SelectedOrder;
        if (order is null)
        {
            SelectedOrderText.Text = "Selecciona una orden pendiente.";
            TotalText.Text = "USD 0.00";
            PaidText.Text = "USD 0.00";
            RemainingText.Text = "USD 0.00";
            return;
        }

        SelectedOrderText.Text = $"Orden POS #{order.Id} - {order.CustomerLabel}";
        TotalText.Text = order.TotalLabel;
        PaidText.Text = order.PaidLabel;
        RemainingText.Text = order.RemainingLabel;
    }

    private void UseRemaining_Click(object sender, RoutedEventArgs e)
    {
        ClearError();
        if (SelectedOrder is not { } order)
        {
            SetError("Selecciona una orden pendiente.");
            return;
        }

        if ((CurrencyBox.SelectedItem as string ?? "USD").Equals("VES", StringComparison.OrdinalIgnoreCase))
        {
            SetError("Para bolivares escribe el monto recibido segun la tasa vigente. El servidor validara la conversion.");
            return;
        }

        AmountBox.Text = order.RemainingBase.ToString("0.00", CultureInfo.InvariantCulture);
        AmountBox.Focus();
        AmountBox.SelectAll();
    }

    private void ClearPayment_Click(object sender, RoutedEventArgs e)
    {
        AmountBox.Text = string.Empty;
        ReferenceBox.Text = string.Empty;
        ClearError();
    }

    private async void Refresh_Click(object sender, RoutedEventArgs e)
    {
        await RefreshAsync();
    }

    private async void AddPayment_Click(object sender, RoutedEventArgs e)
    {
        ClearError();
        PosOrderSummary? order = SelectedOrder;
        if (order is null)
        {
            SetError("Selecciona una orden pendiente.");
            return;
        }

        if (PaymentMethodBox.SelectedItem is not PaymentMethodChoice method)
        {
            SetError("Selecciona un metodo de pago.");
            return;
        }

        string currency = CurrencyBox.SelectedItem as string ?? "";
        if (string.IsNullOrWhiteSpace(currency) || !method.AllowsCurrency(currency))
        {
            SetError("La moneda no esta permitida para ese metodo de pago.");
            return;
        }

        if (!TryReadAmount(out decimal amount))
        {
            SetError("Ingresa un monto valido mayor a cero.");
            return;
        }

        string? reference = string.IsNullOrWhiteSpace(ReferenceBox.Text) ? null : ReferenceBox.Text.Trim();
        if (method.RequiresReference && string.IsNullOrWhiteSpace(reference))
        {
            SetError("Este metodo exige referencia.");
            return;
        }

        PaymentStatusChoice status = new("captured", "Capturado");

        try
        {
            SubmitPaymentButton.IsEnabled = false;
            SubmitPaymentButton.Content = "Procesando...";
            PosOrderResult result = await viewModel.AddPaymentsToPendingOrderAsync(
                order.Id,
                [
                    new PosCheckoutPaymentRequest(
                        method.Id,
                        method.Method,
                        currency,
                        amount,
                        null,
                        status.Code,
                        reference),
                ]);

            string resultMessage = result.Status.Equals("paid", StringComparison.OrdinalIgnoreCase)
                ? $"Orden POS #{result.Id} pagada y cerrada correctamente. La venta ya fue confirmada."
                : $"Pago registrado en la orden POS #{result.Id}. La orden sigue pendiente.";

            MessageBox.Show(
                this,
                resultMessage,
                result.Status.Equals("paid", StringComparison.OrdinalIgnoreCase) ? "Venta confirmada" : "Orden pendiente",
                MessageBoxButton.OK,
                result.Status.Equals("paid", StringComparison.OrdinalIgnoreCase) ? MessageBoxImage.Information : MessageBoxImage.Warning);

            AmountBox.Text = string.Empty;
            ReferenceBox.Text = string.Empty;
            await RefreshAsync();
            SetInfo(resultMessage);
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (InvalidOperationException exception)
        {
            SetError(exception.Message);
        }
        finally
        {
            SubmitPaymentButton.IsEnabled = true;
            SubmitPaymentButton.Content = "Cobrar faltante y cerrar";
        }
    }

    private async void CancelOrder_Click(object sender, RoutedEventArgs e)
    {
        ClearError();
        PosOrderSummary? order = SelectedOrder;
        if (order is null)
        {
            SetError("Selecciona una orden pendiente.");
            return;
        }

        MessageBoxResult confirmation = MessageBox.Show(
            this,
            $"Se cancelara la orden POS #{order.Id} y se liberara el stock o IMEI reservado.\n\nEsta accion no aplica si la orden ya tiene pagos capturados.",
            "Cancelar orden pendiente",
            MessageBoxButton.YesNo,
            MessageBoxImage.Warning);

        if (confirmation != MessageBoxResult.Yes)
        {
            return;
        }

        try
        {
            CancelOrderButton.IsEnabled = false;
            CancelOrderButton.Content = "Cancelando...";
            PosOrderResult result = await viewModel.CancelPendingOrderAsync(order.Id);
            string resultMessage = $"Orden POS #{result.Id} cancelada. La reserva fue liberada.";

            MessageBox.Show(
                this,
                resultMessage,
                "Orden cancelada",
                MessageBoxButton.OK,
                MessageBoxImage.Information);

            await RefreshAsync();
            SetInfo(resultMessage);
        }
        catch (ApiException exception)
        {
            SetError(exception.Message);
        }
        catch (InvalidOperationException exception)
        {
            SetError(exception.Message);
        }
        finally
        {
            CancelOrderButton.IsEnabled = true;
            CancelOrderButton.Content = "Cancelar orden";
        }
    }

    private void Close_Click(object sender, RoutedEventArgs e)
    {
        Close();
    }

    private bool TryReadAmount(out decimal amount)
    {
        string normalized = AmountBox.Text.Trim().Replace(',', '.');
        return decimal.TryParse(normalized, NumberStyles.Number, CultureInfo.InvariantCulture, out amount)
            && amount > 0;
    }

    private void SetError(string message)
    {
        StatusText.Foreground = System.Windows.Media.Brushes.Crimson;
        StatusText.Text = message;
    }

    private void SetInfo(string message)
    {
        StatusText.Foreground = System.Windows.Media.Brushes.SeaGreen;
        StatusText.Text = message;
    }

    private void ClearError()
    {
        StatusText.Text = string.Empty;
    }
}
