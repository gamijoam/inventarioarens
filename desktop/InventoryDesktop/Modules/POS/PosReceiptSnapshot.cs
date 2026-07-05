namespace InventoryDesktop.Modules.POS;

public sealed record PosReceiptSnapshot(
    PosOrderResult Order,
    string CustomerName,
    string PriceListName,
    string CashRegisterLabel,
    string TotalUsdLabel,
    string TotalVesLabel,
    string PaidLabel,
    string ChangeLabel,
    IReadOnlyList<PosReceiptItem> Items,
    IReadOnlyList<PosReceiptPayment> Payments)
{
    public string OrderLabel => $"Orden POS #{Order.Id}";

    public string StatusLabel => Order.Status.Equals("paid", StringComparison.OrdinalIgnoreCase)
        ? "Venta pagada"
        : Order.Status;
}

public sealed record PosReceiptItem(
    string Name,
    string Sku,
    string ControlLabel,
    string SerialLabel,
    string QuantityLabel,
    string UnitPriceLabel,
    string DiscountLabel,
    string TotalLabel);

public sealed record PosReceiptPayment(
    string MethodName,
    string AmountLabel,
    string EquivalentLabel,
    string StatusLabel,
    string ReferenceLabel);
