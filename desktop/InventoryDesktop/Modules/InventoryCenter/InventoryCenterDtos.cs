using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.InventoryCenter;

public sealed record InventoryCenterSummaryResponse(
    [property: JsonPropertyName("data")] InventoryCenterSummaryData Data);

public sealed record InventoryCenterSummaryData(
    [property: JsonPropertyName("filters")] InventoryCenterFilters Filters,
    [property: JsonPropertyName("metrics")] InventoryCenterMetrics Metrics,
    [property: JsonPropertyName("products")] IReadOnlyList<InventoryProductItem> Products,
    [property: JsonPropertyName("pagination")] InventoryPagination Pagination);

public sealed record InventoryCenterFilters(
    [property: JsonPropertyName("search")] string? Search,
    [property: JsonPropertyName("tracking_type")] string? TrackingType,
    [property: JsonPropertyName("stock_status")] string StockStatus,
    [property: JsonPropertyName("low_stock_threshold")] decimal LowStockThreshold,
    [property: JsonPropertyName("limit")] int Limit,
    [property: JsonPropertyName("page")] int Page);

public sealed record InventoryCenterMetrics(
    [property: JsonPropertyName("total_products")] int TotalProducts,
    [property: JsonPropertyName("serialized_products")] int SerializedProducts,
    [property: JsonPropertyName("quantity_products")] int QuantityProducts,
    [property: JsonPropertyName("available_quantity")] decimal AvailableQuantity,
    [property: JsonPropertyName("reserved_quantity")] decimal ReservedQuantity,
    [property: JsonPropertyName("damaged_quantity")] decimal DamagedQuantity,
    [property: JsonPropertyName("low_stock_count")] int LowStockCount,
    [property: JsonPropertyName("without_stock_count")] int WithoutStockCount);

public sealed record InventoryProductItem(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("sku")] string Sku,
    [property: JsonPropertyName("tracking_type")] string TrackingType,
    [property: JsonPropertyName("base_price")] decimal? BasePrice,
    [property: JsonPropertyName("sale_currency")] string SaleCurrency,
    [property: JsonPropertyName("stock")] InventoryProductStock Stock)
{
    public string TrackingLabel => TrackingType == "serialized" ? "Serializado / IMEI" : "Por cantidad";

    public string PriceLabel => BasePrice is null ? "Sin precio" : $"{SaleCurrency} {BasePrice:0.00}";

    public string StockStatusLabel => Stock.Status switch
    {
        "available" => "Disponible",
        "low" => "Stock bajo",
        "out" => "Sin stock",
        _ => Stock.Status,
    };
}

public sealed record InventoryProductStock(
    [property: JsonPropertyName("available")] decimal Available,
    [property: JsonPropertyName("reserved")] decimal Reserved,
    [property: JsonPropertyName("damaged")] decimal Damaged,
    [property: JsonPropertyName("status")] string Status);

public sealed record InventoryPagination(
    [property: JsonPropertyName("page")] int Page,
    [property: JsonPropertyName("limit")] int Limit,
    [property: JsonPropertyName("total")] int Total,
    [property: JsonPropertyName("last_page")] int LastPage,
    [property: JsonPropertyName("from")] int From,
    [property: JsonPropertyName("to")] int To,
    [property: JsonPropertyName("has_previous")] bool HasPrevious,
    [property: JsonPropertyName("has_next")] bool HasNext);
