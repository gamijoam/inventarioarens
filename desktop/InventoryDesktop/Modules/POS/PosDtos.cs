using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.POS;

public sealed record PosCashRegisterSessionListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<PosCashRegisterSession> Data);

public sealed record PosCashRegisterSession(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("cash_register_id")] long? CashRegisterId,
    [property: JsonPropertyName("cashier_id")] long? CashierId,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("opened_at")] string? OpenedAt,
    [property: JsonPropertyName("cash_register")] PosCashRegisterInfo? CashRegister)
{
    public string StatusLabel => Status == "open" ? "Abierta" : Status;

    public string DisplayLabel => CashRegister is null
        ? $"Caja #{Id} - {StatusLabel}"
        : $"{CashRegister.Name} - Turno #{Id}";

    public bool HasPhysicalRegister => CashRegisterId is not null && CashRegister is not null;
}

public sealed record PosCashRegisterInfo(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("status")] string Status);

public sealed record PosOpenCashRegisterRequest(
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("opening_currency")] string OpeningCurrency,
    [property: JsonPropertyName("opening_amount")] decimal OpeningAmount,
    [property: JsonPropertyName("notes")] string? Notes);

public sealed record PosCashRegisterSessionResponse(
    [property: JsonPropertyName("data")] PosCashRegisterSession Data);

public sealed record PosPriceQuoteResponse(
    [property: JsonPropertyName("data")] PosPriceQuote Data);

public sealed record PosPriceQuote(
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("price_list_id")] long? PriceListId,
    [property: JsonPropertyName("price_list_name")] string? PriceListName,
    [property: JsonPropertyName("price_source")] string PriceSource,
    [property: JsonPropertyName("base_price_usd")] decimal BasePriceUsd,
    [property: JsonPropertyName("sale_currency")] string SaleCurrency,
    [property: JsonPropertyName("sale_price")] decimal SalePrice,
    [property: JsonPropertyName("price_usd")] decimal PriceUsd,
    [property: JsonPropertyName("price_ves")] decimal? PriceVes,
    [property: JsonPropertyName("exchange_rate_type_id")] long? ExchangeRateTypeId,
    [property: JsonPropertyName("exchange_rate_type_code")] string? ExchangeRateTypeCode,
    [property: JsonPropertyName("exchange_rate_type_name")] string? ExchangeRateTypeName,
    [property: JsonPropertyName("exchange_rate")] decimal? ExchangeRate)
{
    public string PriceListLabel => string.IsNullOrWhiteSpace(PriceListName)
        ? "Precio base"
        : PriceListName;

    public string RateLabel => ExchangeRate is null
        ? "Sin tasa"
        : $"{ExchangeRateTypeCode ?? "Tasa"} {ExchangeRate:0.##}";
}

public sealed record PosCheckoutRequest(
    [property: JsonPropertyName("cash_register_session_id")] long CashRegisterSessionId,
    [property: JsonPropertyName("customer_id")] long? CustomerId,
    [property: JsonPropertyName("customer_name")] string CustomerName,
    [property: JsonPropertyName("items")] IReadOnlyList<PosCheckoutItemRequest> Items,
    [property: JsonPropertyName("payments")] IReadOnlyList<PosCheckoutPaymentRequest> Payments);

public sealed record PosCheckoutItemRequest(
    [property: JsonPropertyName("warehouse_id")] long WarehouseId,
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("price_list_id")] long? PriceListId,
    [property: JsonPropertyName("quantity")] decimal Quantity,
    [property: JsonPropertyName("product_unit_ids")] IReadOnlyList<long> ProductUnitIds,
    [property: JsonPropertyName("discount_type")] string? DiscountType,
    [property: JsonPropertyName("discount_value")] decimal? DiscountValue,
    [property: JsonPropertyName("discount_reason")] string? DiscountReason);

public sealed record PosCheckoutPaymentRequest(
    [property: JsonPropertyName("payment_method_id")] long? PaymentMethodId,
    [property: JsonPropertyName("method")] string Method,
    [property: JsonPropertyName("currency")] string Currency,
    [property: JsonPropertyName("amount")] decimal Amount,
    [property: JsonPropertyName("exchange_rate_type_id")] long? ExchangeRateTypeId,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("reference")] string? Reference);

public sealed record PosOrderResponse(
    [property: JsonPropertyName("data")] PosOrderResult Data);

public sealed record PosOrderResult(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("code")] string? Code,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("payment_status")] string? PaymentStatus,
    [property: JsonPropertyName("total_base")] decimal? TotalBase,
    [property: JsonPropertyName("paid_base")] decimal? PaidBase);

public sealed record PosOrderListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<PosOrderSummary> Data);

public sealed record PosOrderSummary(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("sale_id")] long SaleId,
    [property: JsonPropertyName("cash_register_session_id")] long CashRegisterSessionId,
    [property: JsonPropertyName("cashier_id")] long CashierId,
    [property: JsonPropertyName("customer_name")] string? CustomerName,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("total_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal TotalBaseAmount,
    [property: JsonPropertyName("total_local_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? TotalLocalAmount,
    [property: JsonPropertyName("paid_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? PaidBaseAmount,
    [property: JsonPropertyName("paid_local_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? PaidLocalAmount,
    [property: JsonPropertyName("opened_at")] string? OpenedAt,
    [property: JsonPropertyName("payments")] IReadOnlyList<PosOrderPaymentSummary>? Payments)
{
    public decimal PaidBase => PaidBaseAmount ?? 0m;

    public decimal RemainingBase => Math.Max(0m, TotalBaseAmount - PaidBase);

    public string CustomerLabel => string.IsNullOrWhiteSpace(CustomerName) ? "Cliente mostrador" : CustomerName;

    public string TotalLabel => $"USD {TotalBaseAmount:0.00}";

    public string PaidLabel => $"USD {PaidBase:0.00}";

    public string RemainingLabel => $"USD {RemainingBase:0.00}";

    public string StatusLabel => Status.Equals("open", StringComparison.OrdinalIgnoreCase) ? "Pendiente" : Status;

    public string PaymentsLabel => Payments is null || Payments.Count == 0
        ? "Sin pagos"
        : $"{Payments.Count} pago(s)";
}

public sealed record PosOrderPaymentSummary(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("method")] string Method,
    [property: JsonPropertyName("currency")] string Currency,
    [property: JsonPropertyName("amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal Amount,
    [property: JsonPropertyName("amount_base")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal AmountBase,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("reference")] string? Reference)
{
    public string AmountLabel => $"{Currency} {Amount:0.00}";

    public string BaseLabel => $"USD {AmountBase:0.00}";
}

public sealed record PosOrderPaymentsRequest(
    [property: JsonPropertyName("payments")] IReadOnlyList<PosCheckoutPaymentRequest> Payments);

public sealed record PosCustomerListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<PosCustomerOption> Data);

public sealed record PosCustomerResponse(
    [property: JsonPropertyName("data")] PosCustomerOption Data);

public sealed record PosCustomerCreateRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("document_type")] string DocumentType,
    [property: JsonPropertyName("document_number")] string DocumentNumber,
    [property: JsonPropertyName("phone")] string? Phone,
    [property: JsonPropertyName("email")] string? Email,
    [property: JsonPropertyName("fiscal_address")] string? FiscalAddress,
    [property: JsonPropertyName("is_generic")] bool IsGeneric,
    [property: JsonPropertyName("is_active")] bool IsActive);

public sealed record PosCustomerOption(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("document_type")] string DocumentType,
    [property: JsonPropertyName("document_number")] string DocumentNumber,
    [property: JsonPropertyName("phone")] string? Phone,
    [property: JsonPropertyName("email")] string? Email,
    [property: JsonPropertyName("is_generic")] bool IsGeneric,
    [property: JsonPropertyName("is_active")] bool IsActive)
{
    public string DocumentLabel => $"{DocumentType}-{DocumentNumber}";

    public string DisplayLabel => IsGeneric ? $"{Name} · genérico" : $"{Name} · {DocumentLabel}";

    public string DetailLabel
    {
        get
        {
            List<string> parts = [DocumentLabel];
            if (!string.IsNullOrWhiteSpace(Phone))
            {
                parts.Add(Phone);
            }

            if (!string.IsNullOrWhiteSpace(Email))
            {
                parts.Add(Email);
            }

            return string.Join(" · ", parts);
        }
    }
}
