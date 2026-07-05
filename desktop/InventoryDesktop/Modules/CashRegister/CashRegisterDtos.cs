using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.CashRegister;

public sealed record CashRegisterSessionListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<CashRegisterSessionItem> Data);

public sealed record CashRegisterSessionResponse(
    [property: JsonPropertyName("data")] CashRegisterSessionItem Data);

public sealed record CashRegisterListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<CashRegisterItem> Data);

public sealed record CashRegisterResponse(
    [property: JsonPropertyName("data")] CashRegisterItem Data);

public sealed record CashRegisterItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("notes")] string? Notes,
    [property: JsonPropertyName("branch")] CashRegisterBranch? Branch,
    [property: JsonPropertyName("open_session")] CashRegisterSessionItem? OpenSession)
{
    public string RegisterLabel => $"{Name} ({Code})";

    public string BranchLabel => Branch?.Name ?? $"Sucursal #{BranchId}";

    public string StatusLabel => Status == "active" ? "Activa" : "Inactiva";

    public string OpenLabel => OpenSession is null
        ? "Disponible"
        : $"Abierta por usuario #{OpenSession.CashierId}";
}

public sealed record CashRegisterSessionItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("cash_register_id")] long? CashRegisterId,
    [property: JsonPropertyName("cashier_id")] long? CashierId,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("opening_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal OpeningBaseAmount,
    [property: JsonPropertyName("opening_local_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal OpeningLocalAmount,
    [property: JsonPropertyName("expected_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal ExpectedBaseAmount,
    [property: JsonPropertyName("expected_local_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal ExpectedLocalAmount,
    [property: JsonPropertyName("counted_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? CountedBaseAmount,
    [property: JsonPropertyName("counted_local_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? CountedLocalAmount,
    [property: JsonPropertyName("difference_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? DifferenceBaseAmount,
    [property: JsonPropertyName("difference_local_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? DifferenceLocalAmount,
    [property: JsonPropertyName("opened_at")] string? OpenedAt,
    [property: JsonPropertyName("closed_at")] string? ClosedAt,
    [property: JsonPropertyName("notes")] string? Notes,
    [property: JsonPropertyName("closing_notes")] string? ClosingNotes,
    [property: JsonPropertyName("branch")] CashRegisterBranch? Branch,
    [property: JsonPropertyName("cash_register")] CashRegisterItem? CashRegister,
    [property: JsonPropertyName("movements")] IReadOnlyList<CashRegisterMovementItem>? Movements)
{
    public string SessionLabel => CashRegister is null
        ? $"Caja #{Id}"
        : $"{CashRegister.Name} - Turno #{Id}";

    public string BranchLabel => Branch?.Name ?? $"Sucursal #{BranchId}";

    public string StatusLabel => Status switch
    {
        "open" => "Abierta",
        "closed" => "Cerrada",
        "cancelled" => "Cancelada",
        _ => Status,
    };

    public string ExpectedLabel => $"USD {ExpectedBaseAmount:0.00} / Bs {ExpectedLocalAmount:0.00}";

    public string OpenedAtLabel
    {
        get
        {
            if (DateTimeOffset.TryParse(OpenedAt, out DateTimeOffset parsed))
            {
                return parsed.LocalDateTime.ToString("dd/MM/yyyy h:mm tt");
            }

            return "Sin fecha";
        }
    }
}

public sealed record CashRegisterMovementItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("type")] string Type,
    [property: JsonPropertyName("method")] string? Method,
    [property: JsonPropertyName("currency")] string Currency,
    [property: JsonPropertyName("amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal Amount,
    [property: JsonPropertyName("amount_base")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal AmountBase,
    [property: JsonPropertyName("amount_local")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? AmountLocal,
    [property: JsonPropertyName("exchange_rate_type_code")] string? ExchangeRateTypeCode,
    [property: JsonPropertyName("exchange_rate")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? ExchangeRate,
    [property: JsonPropertyName("reference")] string? Reference,
    [property: JsonPropertyName("notes")] string? Notes,
    [property: JsonPropertyName("created_at")] string? CreatedAt)
{
    public string TypeLabel => Type switch
    {
        "opening" => "Apertura",
        "inflow" => "Entrada",
        "outflow" => "Salida",
        "pos_payment" => "Pago POS",
        "adjustment" => "Ajuste",
        _ => Type,
    };

    public string MethodLabel => Method switch
    {
        "cash" => "Efectivo",
        "card" => "Tarjeta",
        "mobile_payment" => "Pago movil",
        "transfer" => "Transferencia",
        "zelle" => "Zelle",
        "external_financing" => "Financiadora",
        "other" => "Otro",
        _ => Method ?? "Sin metodo",
    };

    public string AmountLabel => $"{Currency} {Amount:0.00}";

    public string BaseLabel => $"USD {AmountBase:0.00}";

    public string LocalLabel => AmountLocal is null ? "Bs 0.00" : $"Bs {AmountLocal:0.00}";

    public string ReferenceLabel => string.IsNullOrWhiteSpace(Reference) ? "-" : Reference;

    public string NotesLabel => string.IsNullOrWhiteSpace(Notes) ? "-" : Notes;

    public string CreatedAtLabel
    {
        get
        {
            if (DateTimeOffset.TryParse(CreatedAt, out DateTimeOffset parsed))
            {
                return parsed.LocalDateTime.ToString("dd/MM/yyyy h:mm tt");
            }

            return "Sin fecha";
        }
    }
}

public sealed record CashRegisterBranch(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code);

public sealed record OpenCashRegisterRequest(
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("cash_register_id")] long? CashRegisterId,
    [property: JsonPropertyName("opening_currency")] string OpeningCurrency,
    [property: JsonPropertyName("opening_amount")] decimal OpeningAmount,
    [property: JsonPropertyName("notes")] string? Notes);

public sealed record StoreCashRegisterRequest(
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("notes")] string? Notes);

public sealed record UpdateCashRegisterRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("notes")] string? Notes);

public sealed record CloseCashRegisterRequest(
    [property: JsonPropertyName("counted_currency")] string CountedCurrency,
    [property: JsonPropertyName("counted_amount")] decimal CountedAmount,
    [property: JsonPropertyName("closing_notes")] string? ClosingNotes);
