using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.CashRegister;

public sealed record CashRegisterSessionListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<CashRegisterSessionItem> Data);

public sealed record CashRegisterSessionResponse(
    [property: JsonPropertyName("data")] CashRegisterSessionItem Data);

public sealed record CashRegisterSessionItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("cashier_id")] long? CashierId,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("opening_base_amount")] decimal OpeningBaseAmount,
    [property: JsonPropertyName("opening_local_amount")] decimal OpeningLocalAmount,
    [property: JsonPropertyName("expected_base_amount")] decimal ExpectedBaseAmount,
    [property: JsonPropertyName("expected_local_amount")] decimal ExpectedLocalAmount,
    [property: JsonPropertyName("opened_at")] string? OpenedAt,
    [property: JsonPropertyName("notes")] string? Notes,
    [property: JsonPropertyName("branch")] CashRegisterBranch? Branch)
{
    public string SessionLabel => $"Caja #{Id}";

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

public sealed record CashRegisterBranch(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code);

public sealed record OpenCashRegisterRequest(
    [property: JsonPropertyName("branch_id")] long BranchId,
    [property: JsonPropertyName("opening_currency")] string OpeningCurrency,
    [property: JsonPropertyName("opening_amount")] decimal OpeningAmount,
    [property: JsonPropertyName("notes")] string? Notes);
