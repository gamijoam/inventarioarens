using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.Currency;

public sealed record ExchangeRateTypeListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<ExchangeRateTypeItem> Data);

public sealed record ExchangeRateTypeItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("is_default")] bool IsDefault,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("updated_at")] string? UpdatedAt)
{
    public string StatusLabel => IsActive ? "Activo" : "Inactivo";

    public string DefaultLabel => IsDefault ? "Predeterminada" : "Opcional";
}

public sealed record ExchangeRateCurrentResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<ExchangeRateItem> Data);

public sealed record ExchangeRateItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("exchange_rate_type_id")] long ExchangeRateTypeId,
    [property: JsonPropertyName("exchange_rate_type_code")] string? ExchangeRateTypeCode,
    [property: JsonPropertyName("exchange_rate_type_name")] string? ExchangeRateTypeName,
    [property: JsonPropertyName("base_currency")] string BaseCurrency,
    [property: JsonPropertyName("quote_currency")] string QuoteCurrency,
    [property: JsonPropertyName("rate")] decimal Rate,
    [property: JsonPropertyName("effective_at")] string? EffectiveAt,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("source")] string? Source,
    [property: JsonPropertyName("updated_at")] string? UpdatedAt)
{
    public string PairLabel => $"{BaseCurrency}/{QuoteCurrency}";

    public string RateLabel => $"{QuoteCurrency} {Rate:0.####}";

    public string EffectiveLabel => FormatDate(EffectiveAt);

    public string UpdatedLabel => FormatDate(UpdatedAt);

    private static string FormatDate(string? value)
    {
        if (DateTimeOffset.TryParse(value, out DateTimeOffset parsed))
        {
            return parsed.LocalDateTime.ToString("dd/MM/yyyy h:mm tt");
        }

        return "Sin fecha";
    }
}
