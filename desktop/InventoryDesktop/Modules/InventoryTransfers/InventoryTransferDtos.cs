using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.InventoryTransfers;

public sealed record InventoryTransferListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<InventoryTransferItem> Data);

public sealed record InventoryTransferResponse(
    [property: JsonPropertyName("data")] InventoryTransferItem Data);

public sealed record InventoryTransferItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("document_number")] string DocumentNumber,
    [property: JsonPropertyName("guide_number")] string GuideNumber,
    [property: JsonPropertyName("validation_mode")] string ValidationMode,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("reason")] string? Reason,
    [property: JsonPropertyName("reference")] string? Reference,
    [property: JsonPropertyName("from_warehouse")] InventoryTransferWarehouse? FromWarehouse,
    [property: JsonPropertyName("to_warehouse")] InventoryTransferWarehouse? ToWarehouse,
    [property: JsonPropertyName("items")] IReadOnlyList<InventoryTransferLine> Items,
    [property: JsonPropertyName("dispatched_at")] string? DispatchedAt)
{
    public string RouteLabel => $"{FromWarehouse?.Name ?? "Origen"} -> {ToWarehouse?.Name ?? "Destino"}";

    public string StatusLabel => Status switch
    {
        "dispatched" => "Despachado",
        "completed" => "Completado",
        "completed_with_differences" => "Completado con diferencias",
        _ => Status,
    };

    public string ItemsLabel => $"{Items.Count} item(s)";
}

public sealed record InventoryTransferWarehouse(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code);

public sealed record InventoryTransferLine(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("product")] InventoryTransferProduct? Product,
    [property: JsonPropertyName("quantity")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal Quantity,
    [property: JsonPropertyName("prepared_quantity")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? PreparedQuantity,
    [property: JsonPropertyName("received_quantity")]
    [property: JsonNumberHandling(JsonNumberHandling.AllowReadingFromString)]
    decimal? ReceivedQuantity,
    [property: JsonPropertyName("prepared_product_unit_ids")] IReadOnlyList<long>? PreparedProductUnitIds,
    [property: JsonPropertyName("received_product_unit_ids")] IReadOnlyList<long>? ReceivedProductUnitIds);

public sealed record InventoryTransferProduct(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("sku")] string Sku,
    [property: JsonPropertyName("tracking_type")] string TrackingType)
{
    public string TrackingLabel => TrackingType == "serialized" ? "Serializado / IMEI" : "Por cantidad";
}

public sealed record ReceiveInventoryTransferRequest(
    [property: JsonPropertyName("items")] IReadOnlyList<ReceiveInventoryTransferLineRequest> Items);

public sealed record ReceiveInventoryTransferLineRequest(
    [property: JsonPropertyName("inventory_transfer_item_id")] long InventoryTransferItemId,
    [property: JsonPropertyName("received_quantity")] decimal ReceivedQuantity,
    [property: JsonPropertyName("received_product_unit_ids")] IReadOnlyList<long>? ReceivedProductUnitIds,
    [property: JsonPropertyName("difference_reason")] string? DifferenceReason,
    [property: JsonPropertyName("difference_notes")] string? DifferenceNotes);
