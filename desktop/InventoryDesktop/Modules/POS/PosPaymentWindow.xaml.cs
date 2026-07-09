using System.Collections.ObjectModel;
using System.Globalization;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using InventoryDesktop.Core.Api;
using InventoryDesktop.Core.Diagnostics;
using InventoryDesktop.Modules.InventoryCenter;

namespace InventoryDesktop.Modules.POS;

public partial class PosPaymentWindow : Window
{
    private readonly PosViewModel viewModel;
    private readonly ObservableCollection<PaymentLine> payments = new();
    private readonly decimal? orderAverageVesRate;
    private bool hasUnknownBaseAmount;
    private bool isConfirming;

    public PosPaymentWindow(PosViewModel viewModel)
    {
        InitializeComponent();
        this.viewModel = viewModel;
        orderAverageVesRate = viewModel.TotalUsd > 0 && viewModel.TotalVes is not null
            ? viewModel.TotalVes.Value / viewModel.TotalUsd
            : null;

        PaymentsGrid.ItemsSource = payments;
        OrderItemsList.ItemsSource = viewModel.CartItems;
        LoadHeader();
        LoadPaymentStatuses();
        LoadPaymentMethods();
        UpdateTotals();
        PreviewKeyDown += PosPaymentWindow_PreviewKeyDown;
    }

    public bool WasConfirmed { get; private set; }

    public PosReceiptSnapshot? Receipt { get; private set; }

    private void PosPaymentWindow_PreviewKeyDown(object sender, KeyEventArgs e)
    {
        if (e.Key == Key.Escape)
        {
            DialogResult = false;
            Close();
            e.Handled = true;
            return;
        }

        if (e.Key == Key.F12)
        {
            Confirm_Click(sender, e);
            e.Handled = true;
        }
    }

    private void LoadHeader()
    {
        TotalUsdText.Text = viewModel.TotalUsdLabel;
        TotalVesText.Text = viewModel.TotalVesLabel;
        TotalDueText.Text = viewModel.TotalUsdLabel;
        TotalDueInlineText.Text = viewModel.TotalUsdLabel;

        string listLabel = viewModel.SelectedPriceList is null ? "Lista predeterminada" : viewModel.SelectedPriceList.Name;
        string cashRegister = viewModel.SelectedCashRegisterSession?.DisplayLabel ?? "Sin caja";
        ContextText.Text = $"{listLabel} · {cashRegister}. Completa el cobro.";

        if (viewModel.SelectedPriceList?.HasPaymentRestrictions == true)
        {
            RestrictionText.Text = "Métodos permitidos por lista";
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
        BuildQuickPaymentButtons(choices);

        if (choices.Count == 0)
        {
            const string message = "No hay formas de pago activas para esta lista. Configura formas de pago antes de cobrar.";
            ShowAlert(message, "Formas de pago requeridas");
            SetError(message);
        }
    }

    private void LoadPaymentStatuses()
    {
        PaymentStatusBox.ItemsSource = new List<PaymentStatusChoice>
        {
            new("captured", "Registrado"),
            new("pending", "Pendiente"),
        };
        PaymentStatusBox.SelectedIndex = 0;
    }

    private void PaymentMethodBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        RefreshCurrencyOptions();
    }

    private void CurrencyBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        RefreshPaymentRateOptions();
        UpdateFormHelp();
        UpdatePaymentPreview();
    }

    private void PaymentRateBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        UpdateFormHelp();
        UpdatePaymentPreview();
    }

    private void BuildQuickPaymentButtons(IReadOnlyList<PaymentMethodChoice> choices)
    {
        QuickMethodsPanel.Children.Clear();

        foreach (PaymentMethodChoice choice in choices.Take(6))
        {
            StackPanel content = new()
            {
                HorizontalAlignment = HorizontalAlignment.Center,
                VerticalAlignment = VerticalAlignment.Center,
            };
            content.Children.Add(new TextBlock
            {
                Text = QuickPaymentIcon(choice),
                FontSize = 13,
                FontWeight = FontWeights.Black,
                Foreground = new SolidColorBrush(Color.FromRgb(63, 52, 242)),
                HorizontalAlignment = HorizontalAlignment.Center,
            });
            content.Children.Add(new TextBlock
            {
                Text = choice.QuickLabel,
                FontSize = 10,
                FontWeight = FontWeights.Black,
                TextAlignment = TextAlignment.Center,
                TextWrapping = TextWrapping.Wrap,
                HorizontalAlignment = HorizontalAlignment.Center,
                Margin = new Thickness(0, 2, 0, 0),
            });

            Button button = new()
            {
                Content = content,
                Tag = choice,
                Width = 120,
                Height = 50,
                Margin = new Thickness(0, 0, 7, 6),
                FontWeight = FontWeights.Black,
                Background = new SolidColorBrush(Color.FromRgb(248, 250, 254)),
                BorderBrush = new SolidColorBrush(Color.FromRgb(203, 213, 225)),
                Foreground = new SolidColorBrush(Color.FromRgb(17, 24, 39)),
                ToolTip = choice.RequiresReference
                    ? "Completa saldo y pide referencia."
                    : "Completa saldo y agrega.",
            };
            button.Click += QuickPayment_Click;
            QuickMethodsPanel.Children.Add(button);
        }
    }

    private static string QuickPaymentIcon(PaymentMethodChoice choice)
    {
        if (choice.CurrencyMode.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            return "$";
        }

        if (choice.Method.Equals("mobile_payment", StringComparison.OrdinalIgnoreCase))
        {
            return "Bs";
        }

        if (choice.Method.Equals("transfer", StringComparison.OrdinalIgnoreCase))
        {
            return "TRF";
        }

        return "+";
    }

    private void QuickPayment_Click(object sender, RoutedEventArgs e)
    {
        if (sender is not Button { Tag: PaymentMethodChoice choice })
        {
            return;
        }

        PaymentMethodBox.SelectedItem = choice;
        PaymentStatusBox.SelectedIndex = 0;
        FillRemainingAmount();

        if (choice.RequiresReference)
        {
            SetInfo("Monto listo. Ingresa la referencia y presiona Enter o Agregar pago.");
            ReferenceBox.Focus();
            ReferenceBox.SelectAll();
            return;
        }

        AddPayment();
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
        RefreshPaymentRateOptions();
        UpdateFormHelp();
        UpdatePaymentPreview();
    }

    private void RefreshPaymentRateOptions()
    {
        if (PaymentRatePanel is null || PaymentRateBox is null)
        {
            return;
        }

        string currency = CurrencyBox.SelectedItem as string ?? "USD";
        if (!currency.Equals("VES", StringComparison.OrdinalIgnoreCase))
        {
            PaymentRatePanel.Visibility = Visibility.Collapsed;
            return;
        }

        PaymentRatePanel.Visibility = Visibility.Visible;
        PaymentRateBox.ItemsSource = viewModel.PaymentRateChoices;

        PosExchangeRateChoice? selected = PaymentRateBox.SelectedItem as PosExchangeRateChoice;
        if (selected is not null)
        {
            PaymentRateHintText.Text = $"Cobro en Bs usando {selected.ShortLabel}.";
            return;
        }

        List<long> rateTypeIds = viewModel.CartItems
            .Select(item => item.ExchangeRateTypeId)
            .Where(id => id is not null)
            .Select(id => id!.Value)
            .Distinct()
            .ToList();
        long? preferredRateTypeId = rateTypeIds.Count == 1 ? rateTypeIds[0] : null;

        PosExchangeRateChoice? preferred = preferredRateTypeId > 0
            ? viewModel.PaymentRateChoices.FirstOrDefault(rate => rate.TypeId == preferredRateTypeId.Value)
            : null;

        PaymentRateBox.SelectedItem = preferred
            ?? viewModel.PaymentRateChoices.FirstOrDefault(rate => rate.IsDefault)
            ?? viewModel.PaymentRateChoices.FirstOrDefault();

        selected = PaymentRateBox.SelectedItem as PosExchangeRateChoice;
        PaymentRateHintText.Text = selected is null
            ? "No hay tasa activa para calcular pagos en Bs."
            : $"Cobro en Bs usando {selected.ShortLabel}.";
    }

    private void UpdateFormHelp()
    {
        string currency = CurrencyBox.SelectedItem as string ?? "USD";
        FormHelpText.Text = currency == "VES"
            ? "El pago en Bs se convierte con la tasa seleccionada."
            : "Pago directo en dolares.";
    }

    private void AddPayment_Click(object sender, RoutedEventArgs e)
    {
        AddPayment();
    }

    private void UseRemaining_Click(object sender, RoutedEventArgs e)
    {
        FillRemainingAmount();
    }

    private void FillRemainingAmount()
    {
        ClearError();
        string currency = CurrencyBox.SelectedItem as string ?? "USD";
        decimal remainingUsd = RemainingUsd();

        if (remainingUsd <= 0)
        {
            SetError("La venta ya esta cubierta.");
            return;
        }

        decimal? selectedRate = SelectedVesRate();
        decimal suggested = currency.Equals("VES", StringComparison.OrdinalIgnoreCase)
            ? selectedRate is > 0 ? remainingUsd * selectedRate.Value : 0m
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
            SetError("Selecciona una forma de pago.");
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
            SetError("La moneda no esta permitida para esa forma de pago.");
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
            SetError("Esta forma de pago exige referencia.");
            return;
        }

        if (PaymentStatusBox.SelectedItem is not PaymentStatusChoice status)
        {
            SetError("No se pudo preparar el pago.");
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
        if (isConfirming)
        {
            return;
        }

        ClearError();
        if (payments.Count == 0)
        {
            const string message = "Agrega al menos un pago antes de confirmar.";
            ShowAlert(message, "Pago requerido");
            SetError(message);
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
                    "Los pagos registrados no cubren el total. La orden quedara pendiente hasta completar el cobro.\n\n¿Deseas continuar?",
                    "Orden pendiente",
                    MessageBoxButton.YesNo,
                    MessageBoxImage.Warning);

                if (result != MessageBoxResult.Yes)
                {
                    const string message = "Agrega otro pago o confirma la orden pendiente.";
                    ShowAlert(message, "Cobro incompleto");
                    SetError(message);
                    return;
                }
            }
            else
            {
                const string message = "Los pagos registrados todavia no cubren el total.";
                ShowAlert(message, "Cobro incompleto");
                SetError(message);
                return;
            }
        }

        try
        {
            isConfirming = true;
            ConfirmButton.IsEnabled = false;
            CancelButton.IsEnabled = false;
            SetInfo("Procesando venta...");

            List<PosReceiptItem> receiptItems = BuildReceiptItems();
            List<PosReceiptPayment> receiptPayments = BuildReceiptPayments();
            string totalUsdLabel = viewModel.TotalUsdLabel;
            string totalVesLabel = viewModel.TotalVesLabel;
            string paidLabel = PaidText.Text;
            string changeLabel = ChangeText.Text;
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

            PosOrderResult order;
            using (PerformanceTrace.Start("POS confirmar venta", 1000))
            {
                order = await viewModel.SubmitCheckoutAsync(payload);
            }

            WasConfirmed = true;
            if (IsPaidOrder(order))
            {
                using (PerformanceTrace.Start("POS preparar recibo", 250))
                {
                    Receipt = BuildReceipt(order, receiptItems, receiptPayments, totalUsdLabel, totalVesLabel, paidLabel, changeLabel);
                }

                PosReceiptWindow receiptWindow = new(Receipt)
                {
                    Owner = this,
                };
                receiptWindow.ShowDialog();
            }
            else
            {
                MessageBox.Show(
                    this,
                    BuildSuccessMessage(order),
                    "Orden pendiente",
                    MessageBoxButton.OK,
                    MessageBoxImage.Warning);
            }

            DialogResult = true;
            Close();
        }
        catch (ApiException exception)
        {
            string message = FriendlyError(exception.Message);
            ShowAlert(message, "Venta rechazada");
            SetError(message);
        }
        catch (InvalidOperationException exception)
        {
            string message = FriendlyError(exception.Message);
            ShowAlert(message, "No se puede confirmar");
            SetError(message);
        }
        finally
        {
            if (!WasConfirmed)
            {
                isConfirming = false;
                ConfirmButton.IsEnabled = true;
                CancelButton.IsEnabled = true;
            }
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

    private decimal? SelectedVesRate()
    {
        return PaymentRateBox.SelectedItem is PosExchangeRateChoice selectedRate
            ? selectedRate.Rate
            : null;
    }

    private decimal? DisplayVesRate()
    {
        return SelectedVesRate()
            ?? orderAverageVesRate
            ?? viewModel.PaymentRateChoices.FirstOrDefault(rate => rate.IsDefault)?.Rate
            ?? viewModel.PaymentRateChoices.FirstOrDefault()?.Rate;
    }

    private decimal? EstimateBaseUsd(string currency, decimal amount)
    {
        if (currency.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            return amount;
        }

        decimal? selectedRate = SelectedVesRate();
        if (currency.Equals("VES", StringComparison.OrdinalIgnoreCase) && selectedRate is > 0)
        {
            return amount / selectedRate.Value;
        }

        return null;
    }

    private long? ResolvePaymentExchangeRateTypeId(string currency)
    {
        if (!currency.Equals("VES", StringComparison.OrdinalIgnoreCase))
        {
            return null;
        }

        if (PaymentRateBox.SelectedItem is PosExchangeRateChoice selectedRate)
        {
            return selectedRate.TypeId;
        }

        throw new InvalidOperationException("Selecciona una tasa de cobro para registrar pagos en bolivares.");
    }
    private string BuildEquivalentLabel(string currency, decimal amount, decimal? baseUsd)
    {
        if (currency.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            decimal? rate = DisplayVesRate();
            return rate is > 0 ? $"Bs {amount * rate.Value:0.00}" : "Solo USD";
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

    private PosReceiptSnapshot BuildReceipt(
        PosOrderResult order,
        IReadOnlyList<PosReceiptItem> receiptItems,
        IReadOnlyList<PosReceiptPayment> receiptPayments,
        string totalUsdLabel,
        string totalVesLabel,
        string paidLabel,
        string changeLabel)
    {
        string listLabel = viewModel.SelectedPriceList is null ? "Precio base" : viewModel.SelectedPriceList.Name;
        string cashRegister = viewModel.SelectedCashRegisterSession?.DisplayLabel ?? "Sin caja";

        return new PosReceiptSnapshot(
            order,
            viewModel.CustomerLabel,
            listLabel,
            cashRegister,
            totalUsdLabel,
            totalVesLabel,
            paidLabel,
            changeLabel,
            receiptItems,
            receiptPayments);
    }

    private List<PosReceiptItem> BuildReceiptItems()
    {
        return viewModel.CartItems
            .Select(item => new PosReceiptItem(
                item.Name,
                item.Sku,
                item.ControlLabel,
                item.SerialLabel,
                item.QuantityLabel,
                item.UnitPriceLabel,
                item.DiscountLabel,
                item.TotalLabel))
            .ToList();
    }

    private List<PosReceiptPayment> BuildReceiptPayments()
    {
        return payments
            .Select(payment => new PosReceiptPayment(
                payment.MethodName,
                payment.AmountLabel,
                payment.EquivalentLabel,
                payment.StatusLabel,
                payment.ReferenceLabel))
            .ToList();
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
            PaymentPreviewText.Text = "Escribe el monto o usa Saldo exacto.";
            return;
        }

        string currency = CurrencyBox.SelectedItem as string ?? "USD";
        bool isCaptured = PaymentStatusBox.SelectedItem is not PaymentStatusChoice status
            || status.Code.Equals("captured", StringComparison.OrdinalIgnoreCase);

        if (!isCaptured)
        {
            PaymentPreviewText.Text = "Este pago queda pendiente y no completa la venta.";
            return;
        }

        decimal? baseUsd = EstimateBaseUsd(currency, amount);
        if (baseUsd is null)
        {
            PaymentPreviewText.Text = "La conversion se validara al confirmar.";
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
        EmptyPaymentsPanel.Visibility = payments.Count == 0 ? Visibility.Visible : Visibility.Collapsed;
        PaidText.Text = hasUnknownBaseAmount
            ? $"USD {knownPaid:0.00} + pago por validar"
            : $"USD {knownPaid:0.00}";
        RemainingText.Text = hasUnknownBaseAmount
            ? "Por validar"
            : $"USD {remaining:0.00}";
        ChangeText.Text = hasUnknownBaseAmount
            ? "Por validar"
            : $"USD {change:0.00}";
        UpdatePaymentPreview();
    }

    private void SetError(string message)
    {
        StatusText.Foreground = new SolidColorBrush(Color.FromRgb(217, 54, 92));
        StatusText.Text = message;
    }

    private void SetInfo(string message)
    {
        StatusText.Foreground = new SolidColorBrush(Color.FromRgb(81, 97, 127));
        StatusText.Text = message;
    }

    private void ClearError()
    {
        StatusText.Text = string.Empty;
    }

    private void ShowAlert(string message, string title = "Atención", MessageBoxImage icon = MessageBoxImage.Warning)
    {
        MessageBox.Show(this, message, title, MessageBoxButton.OK, icon);
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

    public string QuickLabel => RequiresReference
        ? $"{Name} ref."
        : Name;

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
