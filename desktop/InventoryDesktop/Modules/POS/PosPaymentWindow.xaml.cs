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
        LoadHeader();
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

    private void PaymentMethodBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        RefreshCurrencyOptions();
    }

    private void CurrencyBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        UpdateFormHelp();
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

        decimal? baseUsd = EstimateBaseUsd(currency, amount);
        payments.Add(new PaymentLine(method, currency, amount, baseUsd, reference));
        AmountBox.Text = string.Empty;
        ReferenceBox.Text = string.Empty;
        UpdateTotals();
        AmountBox.Focus();
    }

    private void RemovePayment_Click(object sender, RoutedEventArgs e)
    {
        if (PaymentsGrid.SelectedItem is PaymentLine line)
        {
            payments.Remove(line);
            UpdateTotals();
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

        decimal knownPaid = payments.Sum(payment => payment.BaseAmountUsd ?? 0m);
        hasUnknownBaseAmount = payments.Any(payment => payment.BaseAmountUsd is null);
        if (!hasUnknownBaseAmount && knownPaid + 0.01m < viewModel.TotalUsd)
        {
            SetError("El pago todavía no cubre el total de la venta.");
            return;
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
                    "captured",
                    payment.Reference))
                .ToList();

            PosOrderResult order = await viewModel.SubmitCheckoutAsync(payload);
            WasConfirmed = true;
            MessageBox.Show(
                this,
                $"Venta confirmada correctamente.\nOrden POS #{order.Id}",
                "Venta confirmada",
                MessageBoxButton.OK,
                MessageBoxImage.Information);
            DialogResult = true;
            Close();
        }
        catch (ApiException exception)
        {
            IsEnabled = true;
            SetError(exception.Message);
        }
        catch (InvalidOperationException exception)
        {
            IsEnabled = true;
            SetError(exception.Message);
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

    private void UpdateTotals()
    {
        decimal knownPaid = payments.Sum(payment => payment.BaseAmountUsd ?? 0m);
        hasUnknownBaseAmount = payments.Any(payment => payment.BaseAmountUsd is null);
        decimal remaining = Math.Max(0, viewModel.TotalUsd - knownPaid);

        PaymentsSummaryText.Text = payments.Count == 0
            ? "Sin pagos registrados."
            : $"{payments.Count} pago(s) registrados.";
        PaidText.Text = hasUnknownBaseAmount
            ? $"USD {knownPaid:0.00} + pago por validar"
            : $"USD {knownPaid:0.00}";
        RemainingText.Text = hasUnknownBaseAmount
            ? "Validará servidor"
            : $"USD {remaining:0.00}";
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

public sealed record PaymentLine(
    PaymentMethodChoice MethodChoice,
    string Currency,
    decimal Amount,
    decimal? BaseAmountUsd,
    string? Reference)
{
    public long? PaymentMethodId => MethodChoice.Id;

    public string Method => MethodChoice.Method;

    public string MethodName => MethodChoice.Name;

    public string AmountLabel => $"{Currency} {Amount:0.00}";

    public string BaseUsdLabel => BaseAmountUsd is null ? "Por validar" : $"USD {BaseAmountUsd:0.00}";
}
