using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.POS;

public sealed record PosCashRegisterSessionListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<PosCashRegisterSession> Data);

public sealed record PosCashRegisterSession(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("cashier_id")] long? CashierId,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("opened_at")] string? OpenedAt)
{
    public string StatusLabel => Status == "open" ? "Abierta" : Status;

    public string DisplayLabel => $"Caja #{Id} - {StatusLabel}";
}

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
