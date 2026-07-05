using System.Collections.ObjectModel;
using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public partial class PosPaymentWindow : Window
{
    private readonly PosViewModel viewModel;
    private readonly ObservableCollection<PaymentLine> payments = new();
    private readonly decimal? impliedVesRate;
    private bool hasUnknownBaseAmount;

    public PosPaymentWindow(PosViewModel viewModel)
    {
        InitializeComponent();
        this.viewModel = viewModel;
        impliedVesRate = viewModel.TotalUsd > 0 && viewModel.TotalVes is not null
            ? viewModel.TotalVes.Value / viewModel.TotalUsd
            : null;

        PaymentsGrid.ItemsSource = payments;
        OrderItemsList.ItemsSource = viewModel.CartItems;
        LoadHeader();
        LoadPaymentStatuses();
        LoadPaymentMethods();
        UpdateTotals();
    }

    public bool WasConfirmed { get; private set; }

    private void LoadHeader()
    {
        TotalUsdText.Text = viewModel.TotalUsdLabel;
        TotalVesText.Text = viewModel.TotalVesLabel;

        string listLabel = viewModel.SelectedPriceList is null ? "Lista predeterminada" : viewModel.SelectedPriceList.Name;
        string cashRegister = viewModel.SelectedCashRegisterSession?.DisplayLabel ?? "Sin caja";
        ContextText.Text = $"{listLabel} · {cashRegister}. Agrega uno o varios pagos para completar el total.";

        if (viewModel.SelectedPriceList?.HasPaymentRestrictions == true)
        {
            RestrictionText.Text = "Métodos restringidos por lista";
            return;
        }

        RestrictionText.Text = "Lista abierta";
    }

    private void LoadPaymentMethods()
    {
        List<PaymentMethodChoice> choices = viewModel.GetAllowedPaymentMethods()
            .Select(PaymentMethodChoice.FromConfigured)
            .ToList();

        if (choices.Count == 0 && viewModel.SelectedPriceList?.HasPaymentRestrictions != true)
        {
            choices.Add(new PaymentMethodChoice(null, "Efectivo USD", "cash", "USD", false, 0));
            choices.Add(new PaymentMethodChoice(null, "Pago móvil Bs", "mobile_payment", "VES", true, 1));
            choices.Add(new PaymentMethodChoice(null, "Transferencia Bs", "transfer", "VES", true, 2));
        }

        PaymentMethodBox.ItemsSource = choices;
        PaymentMethodBox.SelectedIndex = choices.Count > 0 ? 0 : -1;

        if (choices.Count == 0)
        {
            SetError("No hay métodos de pago activos para esta lista. Configura métodos de pago antes de cobrar.");
        }
    }

    private void LoadPaymentStatuses()
    {
        PaymentStatusBox.ItemsSource = new List<PaymentStatusChoice>
        {
            new("captured", "Capturado - cuenta para cerrar"),
            new("pending", "Pendiente - no cierra venta"),
        };
        PaymentStatusBox.SelectedIndex = 0;
    }

    private void PaymentMethodBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        RefreshCurrencyOptions();
    }

    private void CurrencyBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        UpdateFormHelp();
        UpdatePaymentPreview();
    }

    private void PaymentStatusBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        UpdatePaymentPreview();
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
        ReferenceBox.Text = string.Empty;
        UpdateFormHelp();
        UpdatePaymentPreview();
    }

    private void UpdateFormHelp()
    {
        string currency = CurrencyBox.SelectedItem as string ?? "USD";
        FormHelpText.Text = currency == "VES"
            ? "El monto en bolívares se convierte a USD con la tasa estimada. Backend recalcula con la tasa activa al confirmar."
            : "El monto en dólares se toma como base directa para completar la venta.";
    }

    private void AddPayment_Click(object sender, RoutedEventArgs e)
    {
        AddPayment();
    }

    private void UseRemaining_Click(object sender, RoutedEventArgs e)
    {
        ClearError();
        string currency = CurrencyBox.SelectedItem as string ?? "USD";
        decimal remainingUsd = RemainingUsd();

        if (remainingUsd <= 0)
        {
            SetError("La venta ya está cubierta con pagos capturados.");
            return;
        }

        decimal suggested = currency.Equals("VES", StringComparison.OrdinalIgnoreCase)
            ? impliedVesRate is > 0 ? remainingUsd * impliedVesRate.Value : 0m
            : remainingUsd;

        if (suggested <= 0)
        {
            SetError("No se puede calcular el faltante en bolívares porque la cotización no tiene tasa.");
            return;
        }

        AmountBox.Text = suggested.ToString("0.00", CultureInfo.InvariantCulture);
        AmountBox.Focus();
        AmountBox.SelectAll();
        UpdatePaymentPreview();
    }

    private void ClearPaymentForm_Click(object sender, RoutedEventArgs e)
    {
        AmountBox.Text = string.Empty;
        ReferenceBox.Text = string.Empty;
        ClearError();
        AmountBox.Focus();
        UpdatePaymentPreview();
    }

    private void AddPayment()
    {
        ClearError();
        if (PaymentMethodBox.SelectedItem is not PaymentMethodChoice method)
        {
            SetError("Selecciona un método de pago.");
            return;
        }

        string currency = CurrencyBox.SelectedItem as string ?? "";
        if (string.IsNullOrWhiteSpace(currency))
        {
            SetError("Selecciona la moneda del pago.");
            return;
        }

        if (!method.AllowsCurrency(currency))
        {
            SetError("La moneda no está permitida para ese método de pago.");
            return;
        }

        if (!TryReadAmount(out decimal amount))
        {
            SetError("Ingresa un monto válido mayor a cero.");
            return;
        }

        string? reference = string.IsNullOrWhiteSpace(ReferenceBox.Text) ? null : ReferenceBox.Text.Trim();
        if (method.RequiresReference && string.IsNullOrWhiteSpace(reference))
        {
            SetError("Este método exige referencia.");
            return;
        }

        if (PaymentStatusBox.SelectedItem is not PaymentStatusChoice status)
        {
            SetError("Selecciona el estado del pago.");
            return;
        }

        long? exchangeRateTypeId;
        try
        {
            exchangeRateTypeId = ResolvePaymentExchangeRateTypeId(currency);
        }
        catch (InvalidOperationException exception)
        {
            SetError(exception.Message);
            return;
        }

        decimal? baseUsd = EstimateBaseUsd(currency, amount);
        payments.Add(new PaymentLine(method, currency, amount, BuildEquivalentLabel(currency, amount, baseUsd), baseUsd, exchangeRateTypeId, reference, status.Code, status.Label));
        AmountBox.Text = string.Empty;
        ReferenceBox.Text = string.Empty;
        PaymentStatusBox.SelectedIndex = 0;
        UpdateTotals();
        UpdatePaymentPreview();
        AmountBox.Focus();
    }

    private void RemovePayment_Click(object sender, RoutedEventArgs e)
    {
        if (PaymentsGrid.SelectedItem is PaymentLine line)
        {
            payments.Remove(line);
            UpdateTotals();
            UpdatePaymentPreview();
        }
    }

    private async void Confirm_Click(object sender, RoutedEventArgs e)
    {
        ClearError();
        if (payments.Count == 0)
        {
            SetError("Agrega al menos un pago antes de confirmar.");
            return;
        }

        decimal knownPaid = CapturedBaseUsd();
        hasUnknownBaseAmount = payments.Any(payment => payment.IsCaptured && payment.BaseAmountUsd is null);
        if (!hasUnknownBaseAmount && knownPaid + 0.01m < viewModel.TotalUsd)
        {
            if (payments.Any())
            {
                MessageBoxResult result = MessageBox.Show(
                    this,
                    "Los pagos capturados no cubren el total. La orden quedará pendiente y la venta no se cerrará hasta completar el cobro.\n\n¿Deseas continuar?",
                    "Orden pendiente",
                    MessageBoxButton.YesNo,
                    MessageBoxImage.Warning);

                if (result != MessageBoxResult.Yes)
                {
                    SetError("Agrega un pago capturado o confirma la orden pendiente.");
                    return;
                }
            }
            else
            {
                SetError("Los pagos capturados todavía no cubren el total.");
                return;
            }
        }

        try
        {
            IsEnabled = false;
            IReadOnlyList<PosCheckoutPaymentRequest> payload = payments
                .Select(payment => new PosCheckoutPaymentRequest(
                    payment.PaymentMethodId,
                    payment.Method,
                    payment.Currency,
                    payment.Amount,
                    payment.ExchangeRateTypeId,
                    payment.Status,
                    payment.Reference))
                .ToList();

            PosOrderResult order = await viewModel.SubmitCheckoutAsync(payload);
            WasConfirmed = true;
            MessageBox.Show(
                this,
                BuildSuccessMessage(order),
                IsPaidOrder(order) ? "Venta confirmada" : "Orden pendiente",
                MessageBoxButton.OK,
                IsPaidOrder(order) ? MessageBoxImage.Information : MessageBoxImage.Warning);
            DialogResult = true;
            Close();
        }
        catch (ApiException exception)
        {
            IsEnabled = true;
            SetError(FriendlyError(exception.Message));
        }
        catch (InvalidOperationException exception)
        {
            IsEnabled = true;
            SetError(FriendlyError(exception.Message));
        }
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
        Close();
    }

    private void AmountBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            AddPayment();
        }
    }

    private void AmountBox_TextChanged(object sender, TextChangedEventArgs e)
    {
        UpdatePaymentPreview();
    }

    private void ReferenceBox_KeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Enter)
        {
            AddPayment();
        }
    }

    private void DecimalInput_PreviewTextInput(object sender, TextCompositionEventArgs e)
    {
        e.Handled = e.Text.Any(character => !char.IsDigit(character) && character is not ',' and not '.');
    }

    private bool TryReadAmount(out decimal amount)
    {
        string normalized = AmountBox.Text.Trim().Replace(',', '.');
        return decimal.TryParse(normalized, NumberStyles.Number, CultureInfo.InvariantCulture, out amount)
            && amount > 0;
    }

    private decimal? EstimateBaseUsd(string currency, decimal amount)
    {
        if (currency.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            return amount;
        }

        if (currency.Equals("VES", StringComparison.OrdinalIgnoreCase) && impliedVesRate is > 0)
        {
            return amount / impliedVesRate.Value;
        }

        return null;
    }

    private long? ResolvePaymentExchangeRateTypeId(string currency)
    {
        if (!currency.Equals("VES", StringComparison.OrdinalIgnoreCase))
        {
            return null;
        }

        List<long> rateTypeIds = viewModel.CartItems
            .Select(item => item.ExchangeRateTypeId)
            .Where(id => id is not null)
            .Select(id => id!.Value)
            .Distinct()
            .ToList();

        if (rateTypeIds.Count > 1)
        {
            throw new InvalidOperationException("La orden mezcla productos con tasas diferentes. Para cobrar en bolívares, separa la venta por tasa o cobra en dólares.");
        }

        return rateTypeIds.Count == 0 ? null : rateTypeIds[0];
    }

    private string BuildEquivalentLabel(string currency, decimal amount, decimal? baseUsd)
    {
        if (currency.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            return impliedVesRate is > 0 ? $"Bs {amount * impliedVesRate.Value:0.00}" : "Solo USD";
        }

        if (currency.Equals("VES", StringComparison.OrdinalIgnoreCase))
        {
            return baseUsd is null ? "Por validar" : $"USD {baseUsd.Value:0.00}";
        }

        return baseUsd is null ? "Por validar" : $"USD {baseUsd.Value:0.00}";
    }

    private static string FriendlyError(string message)
    {
        if (message.Contains("pertenece a otro cajero", StringComparison.OrdinalIgnoreCase))
        {
            return "La caja seleccionada pertenece a otro cajero. Recarga el contexto y selecciona una caja abierta a tu nombre.";
        }

        if (message.Contains("no esta abierta", StringComparison.OrdinalIgnoreCase) || message.Contains("no está abierta", StringComparison.OrdinalIgnoreCase))
        {
            return "La caja seleccionada ya no está abierta. Recarga el contexto antes de continuar.";
        }

        return message;
    }

    private static string BuildSuccessMessage(PosOrderResult order)
    {
        if (IsPaidOrder(order))
        {
            return $"Venta confirmada correctamente.\nOrden POS #{order.Id}";
        }

        return $"Orden POS #{order.Id} registrada como pendiente.\nCompleta el cobro para cerrar la venta.";
    }

    private static bool IsPaidOrder(PosOrderResult order)
    {
        return order.Status.Equals("paid", StringComparison.OrdinalIgnoreCase)
            || order.Status.Equals("closed", StringComparison.OrdinalIgnoreCase)
            || order.PaymentStatus?.Equals("paid", StringComparison.OrdinalIgnoreCase) == true;
    }

    private decimal CapturedBaseUsd()
    {
        return payments
            .Where(payment => payment.IsCaptured)
            .Sum(payment => payment.BaseAmountUsd ?? 0m);
    }

    private decimal RemainingUsd()
    {
        return Math.Max(0, viewModel.TotalUsd - CapturedBaseUsd());
    }

    private void UpdatePaymentPreview()
    {
        if (PaymentPreviewText is null)
        {
            return;
        }

        if (!TryReadAmount(out decimal amount))
        {
            PaymentPreviewText.Text = "Escribe el monto recibido o usa Completar saldo.";
            return;
        }

        string currency = CurrencyBox.SelectedItem as string ?? "USD";
        bool isCaptured = PaymentStatusBox.SelectedItem is not PaymentStatusChoice status
            || status.Code.Equals("captured", StringComparison.OrdinalIgnoreCase);

        if (!isCaptured)
        {
            PaymentPreviewText.Text = "Al agregarlo como pendiente, quedará registrado pero no cerrará la venta.";
            return;
        }

        decimal? baseUsd = EstimateBaseUsd(currency, amount);
        if (baseUsd is null)
        {
            PaymentPreviewText.Text = "Al agregarlo, el servidor validará la conversión porque no hay tasa local suficiente.";
            return;
        }

        decimal projectedPaid = CapturedBaseUsd() + baseUsd.Value;
        decimal projectedRemaining = Math.Max(0, viewModel.TotalUsd - projectedPaid);
        decimal projectedChange = Math.Max(0, projectedPaid - viewModel.TotalUsd);
        PaymentPreviewText.Text = $"Al agregar: pagado USD {projectedPaid:0.00} - faltante USD {projectedRemaining:0.00} - vuelto USD {projectedChange:0.00}.";
    }

    private void UpdateTotals()
    {
        decimal knownPaid = CapturedBaseUsd();
        hasUnknownBaseAmount = payments.Any(payment => payment.IsCaptured && payment.BaseAmountUsd is null);
        decimal remaining = Math.Max(0, viewModel.TotalUsd - knownPaid);
        decimal change = Math.Max(0, knownPaid - viewModel.TotalUsd);
        int pendingCount = payments.Count(payment => !payment.IsCaptured);

        PaymentsSummaryText.Text = payments.Count == 0
            ? "Sin pagos registrados."
            : pendingCount == 0
                ? $"{payments.Count} pago(s) registrados."
                : $"{payments.Count} pago(s) registrados, {pendingCount} pendiente(s).";
        PaidText.Text = hasUnknownBaseAmount
            ? $"USD {knownPaid:0.00} + pago por validar"
            : $"USD {knownPaid:0.00}";
        RemainingText.Text = hasUnknownBaseAmount
            ? "Validará servidor"
            : $"USD {remaining:0.00}";
        ChangeText.Text = hasUnknownBaseAmount
            ? "Por validar"
            : $"USD {change:0.00}";
        UpdatePaymentPreview();
    }

    private void SetError(string message)
    {
        StatusText.Text = message;
    }

    private void ClearError()
    {
        StatusText.Text = string.Empty;
    }
}

public sealed record PaymentMethodChoice(
    long? Id,
    string Name,
    string Method,
    string CurrencyMode,
    bool RequiresReference,
    int SortOrder)
{
    public string DisplayLabel => RequiresReference
        ? $"{Name} ({CurrencyLabel}) - ref."
        : $"{Name} ({CurrencyLabel})";

    private string CurrencyLabel => CurrencyMode.Equals("flexible", StringComparison.OrdinalIgnoreCase)
        ? "USD/Bs"
        : CurrencyMode.ToUpperInvariant();

    public static PaymentMethodChoice FromConfigured(PaymentMethodOption option)
    {
        return new PaymentMethodChoice(
            option.Id,
            option.Name,
            option.Method,
            option.CurrencyMode,
            option.RequiresReference,
            option.SortOrder);
    }

    public bool AllowsCurrency(string currency)
    {
        return CurrencyMode.Equals("flexible", StringComparison.OrdinalIgnoreCase)
            || CurrencyMode.Equals(currency, StringComparison.OrdinalIgnoreCase);
    }
}

public sealed record PaymentStatusChoice(string Code, string Label);

public sealed record PaymentLine(
    PaymentMethodChoice MethodChoice,
    string Currency,
    decimal Amount,
    string EquivalentLabel,
    decimal? BaseAmountUsd,
    long? ExchangeRateTypeId,
    string? Reference,
    string Status,
    string StatusLabel)
{
    public long? PaymentMethodId => MethodChoice.Id;

    public string Method => MethodChoice.Method;

    public string MethodName => MethodChoice.Name;

    public string AmountLabel => $"{Currency} {Amount:0.00}";

    public string BaseUsdLabel => BaseAmountUsd is null ? "Por validar" : $"USD {BaseAmountUsd:0.00}";

    public string ReferenceLabel => string.IsNullOrWhiteSpace(Reference) ? "Sin referencia" : Reference;

    public bool IsCaptured => Status.Equals("captured", StringComparison.OrdinalIgnoreCase);
}
