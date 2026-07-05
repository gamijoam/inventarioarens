using System.Globalization;
using System.Text.RegularExpressions;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;

namespace InventoryDesktop.Modules.POS;

public partial class PosDiscountWindow : Window
{
    private readonly PosCartItem item;

    public PosDiscountWindow(PosCartItem item)
    {
        InitializeComponent();
        this.item = item;
        ProductNameText.Text = item.Name;
        ProductTotalText.Text = $"Total original: USD {item.GrossTotalUsd:0.00}";
        DiscountTypeBox.SelectedIndex = item.DiscountType == "fixed" ? 1 : 0;
        ValueBox.Text = item.HasDiscount ? item.DiscountValue.ToString("0.##", CultureInfo.InvariantCulture) : "";
        ReasonBox.Text = item.DiscountReason ?? "";
        UpdatePreview();
    }

    public bool ShouldClearDiscount { get; private set; }

    public string DiscountType { get; private set; } = "percent";

    public decimal DiscountValue { get; private set; }

    public string DiscountReason { get; private set; } = "";

    private void DiscountTypeBox_SelectionChanged(object sender, SelectionChangedEventArgs e)
    {
        ValueLabel.Text = SelectedDiscountType() == "percent"
            ? "Porcentaje"
            : $"Monto fijo ({item.SaleCurrency})";
        UpdatePreview();
    }

    private void ValueBox_TextChanged(object sender, TextChangedEventArgs e)
    {
        UpdatePreview();
    }

    private void Apply_Click(object sender, RoutedEventArgs e)
    {
        ErrorText.Text = "";
        if (!TryReadValue(out decimal value))
        {
            ErrorText.Text = "Ingresa un valor mayor a cero.";
            return;
        }

        string type = SelectedDiscountType();
        if (type == "percent" && value > 100)
        {
            ErrorText.Text = "El porcentaje no puede superar 100%.";
            return;
        }

        decimal max = type == "percent" ? 100m : item.SalePrice * item.Quantity;
        if (value > max)
        {
            ErrorText.Text = "El descuento no puede ser mayor al total del producto.";
            return;
        }

        if (string.IsNullOrWhiteSpace(ReasonBox.Text))
        {
            ErrorText.Text = "Indica un motivo para auditar el descuento.";
            return;
        }

        DiscountType = type;
        DiscountValue = value;
        DiscountReason = ReasonBox.Text.Trim();
        DialogResult = true;
    }

    private void Clear_Click(object sender, RoutedEventArgs e)
    {
        ShouldClearDiscount = true;
        DialogResult = true;
    }

    private void Cancel_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = false;
    }

    private void DecimalInput_PreviewTextInput(object sender, TextCompositionEventArgs e)
    {
        e.Handled = !Regex.IsMatch(e.Text, "^[0-9.,]+$");
    }

    private string SelectedDiscountType()
    {
        return DiscountTypeBox.SelectedItem is ComboBoxItem item && item.Tag is string tag
            ? tag
            : "percent";
    }

    private bool TryReadValue(out decimal value)
    {
        string normalized = ValueBox.Text.Trim().Replace(',', '.');
        return decimal.TryParse(normalized, NumberStyles.Number, CultureInfo.InvariantCulture, out value) && value > 0;
    }

    private void UpdatePreview()
    {
        if (!IsLoaded)
        {
            return;
        }

        if (!TryReadValue(out decimal value))
        {
            PreviewText.Text = "Indica el descuento y el motivo.";
            return;
        }

        string type = SelectedDiscountType();
        decimal discount = type == "percent"
            ? item.GrossTotalUsd * value / 100m
            : EstimateFixedDiscountUsd(value);
        decimal total = Math.Max(0m, item.GrossTotalUsd - discount);
        PreviewText.Text = $"Total estimado: USD {total:0.00}";
    }

    private decimal EstimateFixedDiscountUsd(decimal value)
    {
        if (item.SaleCurrency.Equals("USD", StringComparison.OrdinalIgnoreCase))
        {
            return value;
        }

        if (item.UnitPriceVes is null || item.UnitPriceVes <= 0 || item.UnitPriceUsd <= 0)
        {
            return 0m;
        }

        decimal rate = item.UnitPriceVes.Value / item.UnitPriceUsd;
        return value / rate;
    }
}
