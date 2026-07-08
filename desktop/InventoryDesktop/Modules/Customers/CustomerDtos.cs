using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.Customers;

public sealed record CustomerListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<CustomerItem> Data);

public sealed record CustomerResponse(
    [property: JsonPropertyName("data")] CustomerItem Data);

public sealed record CustomerDetailResponse(
    [property: JsonPropertyName("data")] CustomerDetail Data);

public sealed record CustomerItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("document_type")] string DocumentType,
    [property: JsonPropertyName("document_number")] string DocumentNumber,
    [property: JsonPropertyName("phone")] string? Phone,
    [property: JsonPropertyName("email")] string? Email,
    [property: JsonPropertyName("fiscal_address")] string? FiscalAddress,
    [property: JsonPropertyName("is_generic")] bool IsGeneric,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("created_at")] string? CreatedAt,
    [property: JsonPropertyName("updated_at")] string? UpdatedAt)
{
    public string DocumentLabel => $"{DocumentType}-{DocumentNumber}";

    public string StatusLabel => IsActive ? "Activo" : "Inactivo";

    public string TypeLabel => IsGeneric ? "Consumidor final" : "Cliente";

    public string ContactLabel
    {
        get
        {
            List<string> parts = [];
            if (!string.IsNullOrWhiteSpace(Phone))
            {
                parts.Add(Phone);
            }

            if (!string.IsNullOrWhiteSpace(Email))
            {
                parts.Add(Email);
            }

            return parts.Count == 0 ? "Sin contacto" : string.Join(" · ", parts);
        }
    }
}

public sealed record CustomerDetail(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("document_type")] string DocumentType,
    [property: JsonPropertyName("document_number")] string DocumentNumber,
    [property: JsonPropertyName("phone")] string? Phone,
    [property: JsonPropertyName("email")] string? Email,
    [property: JsonPropertyName("fiscal_address")] string? FiscalAddress,
    [property: JsonPropertyName("is_generic")] bool IsGeneric,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("pos_history")] CustomerHistory? PosHistory);

public sealed record CustomerHistory(
    [property: JsonPropertyName("total_orders")] int TotalOrders,
    [property: JsonPropertyName("paid_orders")] int PaidOrders,
    [property: JsonPropertyName("open_orders")] int OpenOrders,
    [property: JsonPropertyName("total_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal TotalBaseAmount,
    [property: JsonPropertyName("paid_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal PaidBaseAmount,
    [property: JsonPropertyName("balance_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal BalanceBaseAmount,
    [property: JsonPropertyName("last_order_at")] string? LastOrderAt,
    [property: JsonPropertyName("recent_orders")] IReadOnlyList<CustomerRecentOrder> RecentOrders)
{
    public string OrdersLabel => $"{TotalOrders} compra(s)";

    public string PaidLabel => $"Pagadas: {PaidOrders}";

    public string OpenLabel => $"Pendientes: {OpenOrders}";

    public string TotalLabel => $"USD {TotalBaseAmount:0.00}";

    public string BalanceLabel => $"Saldo USD {BalanceBaseAmount:0.00}";

    public string LastOrderLabel => string.IsNullOrWhiteSpace(LastOrderAt)
        ? "Sin compras registradas"
        : $"Última compra: {LastOrderAt}";
}

public sealed record CustomerRecentOrder(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("status_label")] string StatusLabel,
    [property: JsonPropertyName("total_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal TotalBaseAmount,
    [property: JsonPropertyName("paid_base_amount")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal PaidBaseAmount,
    [property: JsonPropertyName("opened_at")] string? OpenedAt,
    [property: JsonPropertyName("paid_at")] string? PaidAt)
{
    public string OrderLabel => $"POS #{Id}";

    public string TotalLabel => $"USD {TotalBaseAmount:0.00}";

    public string PaidLabel => $"Pagado USD {PaidBaseAmount:0.00}";

    public string DateLabel => string.IsNullOrWhiteSpace(OpenedAt) ? "Sin fecha" : OpenedAt;
}

public sealed record CustomerSaveRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("document_type")] string DocumentType,
    [property: JsonPropertyName("document_number")] string DocumentNumber,
    [property: JsonPropertyName("phone")] string? Phone,
    [property: JsonPropertyName("email")] string? Email,
    [property: JsonPropertyName("fiscal_address")] string? FiscalAddress,
    [property: JsonPropertyName("is_generic")] bool IsGeneric,
    [property: JsonPropertyName("is_active")] bool IsActive);
