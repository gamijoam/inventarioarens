using System.Text.Json;
using System.Text.Json.Serialization;

namespace InventoryDesktop.Modules.InventoryCenter;

public sealed record InventoryCenterSummaryResponse(
    [property: JsonPropertyName("data")] InventoryCenterSummaryData Data);

public sealed record InventoryCenterSummaryData(
    [property: JsonPropertyName("filters")] InventoryCenterFilters Filters,
    [property: JsonPropertyName("metrics")] InventoryCenterMetrics Metrics,
    [property: JsonPropertyName("alerts")] IReadOnlyList<InventoryCenterAlert> Alerts,
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

public sealed record InventoryCenterAlert(
    [property: JsonPropertyName("type")] string Type,
    [property: JsonPropertyName("severity")] string Severity,
    [property: JsonPropertyName("title")] string Title,
    [property: JsonPropertyName("count")] int Count,
    [property: JsonPropertyName("message")] string Message,
    [property: JsonPropertyName("action")] string Action,
    [property: JsonPropertyName("product_names")] IReadOnlyList<string> ProductNames)
{
    public string CountLabel => Count.ToString("N0", System.Globalization.CultureInfo.CurrentCulture);

    public string ProductNamesLabel => ProductNames.Count == 0
        ? "Sin ejemplos para mostrar."
        : string.Join(", ", ProductNames);

    public string SeverityLabel => Severity switch
    {
        "danger" => "Crítico",
        "warning" => "Atención",
        "info" => "Info",
        _ => Severity,
    };
}

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

public sealed record InventoryProductDetailResponse(
    [property: JsonPropertyName("data")] InventoryProductDetailData Data);

public sealed record InventoryProductDetailData(
    [property: JsonPropertyName("product")] InventoryProductDetail Product,
    [property: JsonPropertyName("stock")] InventoryProductDetailStock Stock,
    [property: JsonPropertyName("serials")] InventoryProductSerials Serials,
    [property: JsonPropertyName("recent_movements")] IReadOnlyList<InventoryProductMovement> RecentMovements,
    [property: JsonPropertyName("recent_audits")] IReadOnlyList<InventoryProductAudit> RecentAudits);

public sealed record InventoryProductDetail(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("sku")] string Sku,
    [property: JsonPropertyName("tracking_type")] string TrackingType,
    [property: JsonPropertyName("base_price")] decimal? BasePrice,
    [property: JsonPropertyName("sale_currency")] string SaleCurrency,
    [property: JsonPropertyName("sale_exchange_rate_type")] InventoryExchangeRateType? SaleExchangeRateType,
    [property: JsonPropertyName("warranty_policy")] InventoryWarrantyPolicy? WarrantyPolicy,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("updated_at")] string? UpdatedAt)
{
    public string TrackingLabel => TrackingType == "serialized" ? "Serializado / IMEI" : "Por cantidad";

    public string PriceLabel => BasePrice is null ? "Sin precio" : $"{SaleCurrency} {BasePrice:0.00}";

    public string StatusLabel => IsActive ? "Activo" : "Inactivo";

    public string RateLabel => SaleExchangeRateType is null ? "Tasa no asignada" : SaleExchangeRateType.Name;

    public string WarrantyLabel => WarrantyPolicy is null
        ? "Sin política de garantía"
        : $"{WarrantyPolicy.Name} · {WarrantyPolicy.DurationDays} días";
}

public sealed record InventoryExchangeRateType(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("is_default")] bool IsDefault,
    [property: JsonPropertyName("is_active")] bool IsActive);

public sealed record InventoryWarrantyPolicy(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("duration_days")] int DurationDays,
    [property: JsonPropertyName("coverage_type")] string CoverageType,
    [property: JsonPropertyName("is_active")] bool IsActive);

public sealed record InventoryProductDetailStock(
    [property: JsonPropertyName("totals")] InventoryProductStock Totals,
    [property: JsonPropertyName("by_warehouse")] IReadOnlyList<InventoryWarehouseStock> ByWarehouse);

public sealed record InventoryWarehouseStock(
    [property: JsonPropertyName("warehouse_id")] long WarehouseId,
    [property: JsonPropertyName("warehouse_name")] string? WarehouseName,
    [property: JsonPropertyName("warehouse_code")] string? WarehouseCode,
    [property: JsonPropertyName("branch_name")] string? BranchName,
    [property: JsonPropertyName("available")] decimal Available,
    [property: JsonPropertyName("reserved")] decimal Reserved,
    [property: JsonPropertyName("damaged")] decimal Damaged)
{
    public string WarehouseLabel => string.IsNullOrWhiteSpace(WarehouseName) ? "Almacén sin nombre" : WarehouseName;

    public string BranchLabel => string.IsNullOrWhiteSpace(BranchName) ? "Sucursal no asignada" : BranchName;
}

public sealed record InventoryProductSerials(
    [property: JsonPropertyName("total")] int Total,
    [property: JsonPropertyName("items")] IReadOnlyList<InventoryProductSerial> Items);

public sealed record InventoryProductSerial(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("serial_type")] string SerialType,
    [property: JsonPropertyName("serial_number")] string SerialNumber,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("warehouse_name")] string? WarehouseName)
{
    public string StatusLabel => Status switch
    {
        "available" => "Disponible",
        "reserved" => "Reservado",
        "sold" => "Vendido",
        "damaged" => "Dañado",
        "removed" => "Removido",
        "warranty_hold" => "Garantía",
        _ => Status,
    };

    public string WarehouseLabel => string.IsNullOrWhiteSpace(WarehouseName) ? "Sin almacén" : WarehouseName;
}

public sealed record InventoryProductMovement(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("type")] string Type,
    [property: JsonPropertyName("quantity")] decimal Quantity,
    [property: JsonPropertyName("reason")] string? Reason,
    [property: JsonPropertyName("warehouse_name")] string? WarehouseName,
    [property: JsonPropertyName("created_by_name")] string? CreatedByName,
    [property: JsonPropertyName("created_at")] string? CreatedAt)
{
    public string TypeLabel => Type switch
    {
        "purchase" => "Entrada",
        "sale" => "Venta",
        "adjustment_in" => "Ajuste entrada",
        "adjustment_out" => "Ajuste salida",
        "reserve" => "Reserva",
        "release" => "Liberación",
        "damage" => "Dañado",
        "transfer_in" => "Traslado entrada",
        "transfer_out" => "Traslado salida",
        "purchase_return" => "Dev. proveedor",
        "sale_return" => "Dev. venta",
        _ => Type,
    };

    public string ReasonLabel => string.IsNullOrWhiteSpace(Reason) ? "Sin motivo" : Reason;

    public string WarehouseLabel => string.IsNullOrWhiteSpace(WarehouseName) ? "Sin almacén" : WarehouseName;

    public string CreatedByLabel => string.IsNullOrWhiteSpace(CreatedByName) ? "Sistema" : CreatedByName;

    public string DateLabel
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

public sealed record InventoryProductAudit(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("action")] string Action,
    [property: JsonPropertyName("changes")] JsonElement? Changes,
    [property: JsonPropertyName("created_by_name")] string? CreatedByName,
    [property: JsonPropertyName("created_by_email")] string? CreatedByEmail,
    [property: JsonPropertyName("created_at")] string? CreatedAt)
{
    public string ActionLabel => Action switch
    {
        "created" => "Creado",
        "updated" => "Actualizado",
        "deleted" => "Eliminado",
        _ => Action,
    };

    public string CreatedByLabel => string.IsNullOrWhiteSpace(CreatedByName) ? "Sistema" : CreatedByName;

    public string CreatedByEmailLabel => string.IsNullOrWhiteSpace(CreatedByEmail) ? "Sin correo" : CreatedByEmail;

    public string DateLabel
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

    public string ChangesLabel
    {
        get
        {
            if (Changes is null || Changes.Value.ValueKind is JsonValueKind.Null or JsonValueKind.Undefined)
            {
                return "Sin cambios detallados";
            }

            return Changes.Value.GetRawText();
        }
    }
}

public sealed record InventoryProductAuditsPageResponse(
    [property: JsonPropertyName("data")] InventoryProductAuditsPageData Data);

public sealed record InventoryProductAuditsPageData(
    [property: JsonPropertyName("filters")] InventoryProductAuditsPageFilters Filters,
    [property: JsonPropertyName("data")] IReadOnlyList<InventoryProductAudit> Data,
    [property: JsonPropertyName("pagination")] InventoryPagination Pagination);

public sealed record InventoryProductAuditsPageFilters(
    [property: JsonPropertyName("search")] string? Search,
    [property: JsonPropertyName("action")] string Action,
    [property: JsonPropertyName("limit")] int Limit,
    [property: JsonPropertyName("page")] int Page);

public sealed record InventoryProductSerialsPageResponse(
    [property: JsonPropertyName("data")] InventoryProductSerialsPageData Data);

public sealed record InventoryProductSerialsPageData(
    [property: JsonPropertyName("filters")] InventoryProductSerialsPageFilters Filters,
    [property: JsonPropertyName("data")] IReadOnlyList<InventoryProductSerial> Data,
    [property: JsonPropertyName("pagination")] InventoryPagination Pagination);

public sealed record InventoryProductSerialsPageFilters(
    [property: JsonPropertyName("search")] string? Search,
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("warehouse_id")] long? WarehouseId,
    [property: JsonPropertyName("limit")] int Limit,
    [property: JsonPropertyName("page")] int Page);

public sealed record InventoryProductMovementsPageResponse(
    [property: JsonPropertyName("data")] InventoryProductMovementsPageData Data);

public sealed record InventoryProductMovementsPageData(
    [property: JsonPropertyName("filters")] InventoryProductMovementsPageFilters Filters,
    [property: JsonPropertyName("data")] IReadOnlyList<InventoryProductMovement> Data,
    [property: JsonPropertyName("pagination")] InventoryPagination Pagination);

public sealed record InventoryProductMovementsPageFilters(
    [property: JsonPropertyName("search")] string? Search,
    [property: JsonPropertyName("type")] string Type,
    [property: JsonPropertyName("warehouse_id")] long? WarehouseId,
    [property: JsonPropertyName("date_from")] string? DateFrom,
    [property: JsonPropertyName("date_to")] string? DateTo,
    [property: JsonPropertyName("limit")] int Limit,
    [property: JsonPropertyName("page")] int Page);

public sealed record InventoryProductKardexResponse(
    [property: JsonPropertyName("data")] InventoryProductKardexData Data);

public sealed record InventoryProductKardexData(
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("product_name")] string ProductName,
    [property: JsonPropertyName("warehouse_id")] long? WarehouseId,
    [property: JsonPropertyName("date_from")] string? DateFrom,
    [property: JsonPropertyName("date_to")] string? DateTo,
    [property: JsonPropertyName("opening_balance")] decimal OpeningBalance,
    [property: JsonPropertyName("closing_balance")] decimal ClosingBalance,
    [property: JsonPropertyName("movements")] IReadOnlyList<InventoryProductKardexMovement> Movements);

public sealed record InventoryProductKardexMovement(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("date")] string? Date,
    [property: JsonPropertyName("warehouse_id")] long? WarehouseId,
    [property: JsonPropertyName("warehouse_name")] string? WarehouseName,
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("product_name")] string? ProductName,
    [property: JsonPropertyName("type")] string Type,
    [property: JsonPropertyName("quantity_in")] decimal QuantityIn,
    [property: JsonPropertyName("quantity_out")] decimal QuantityOut,
    [property: JsonPropertyName("running_balance")] decimal RunningBalance,
    [property: JsonPropertyName("unit_cost")] decimal? UnitCost,
    [property: JsonPropertyName("reason")] string? Reason,
    [property: JsonPropertyName("reference_type")] string? ReferenceType,
    [property: JsonPropertyName("reference_id")] long? ReferenceId)
{
    public string DateLabel
    {
        get
        {
            if (DateTimeOffset.TryParse(Date, out DateTimeOffset parsed))
            {
                return parsed.LocalDateTime.ToString("dd/MM/yyyy h:mm tt");
            }

            return "Sin fecha";
        }
    }

    public string TypeLabel => Type switch
    {
        "purchase" => "Entrada",
        "sale_return" => "Dev. venta",
        "adjustment_in" => "Ajuste entrada",
        "transfer_in" => "Traslado entrada",
        "return_in" => "Retorno entrada",
        "released" => "Liberación",
        "purchase_return" => "Dev. proveedor",
        "sale" => "Venta",
        "adjustment_out" => "Ajuste salida",
        "transfer_out" => "Traslado salida",
        "return_out" => "Retorno salida",
        "damaged" => "Dañado",
        "reserved" => "Reserva",
        _ => Type,
    };

    public string WarehouseLabel => string.IsNullOrWhiteSpace(WarehouseName) ? "Sin almacén" : WarehouseName;

    public string ReasonLabel => string.IsNullOrWhiteSpace(Reason) ? "Sin motivo" : Reason;
}

public sealed record ProductEntryStoreRequest(
    [property: JsonPropertyName("reason")] string Reason,
    [property: JsonPropertyName("reference")] string? Reference,
    [property: JsonPropertyName("notes")] string? Notes,
    [property: JsonPropertyName("items")] IReadOnlyList<ProductEntryStoreItemRequest> Items);

public sealed record ProductEntryStoreItemRequest(
    [property: JsonPropertyName("warehouse_id")] long WarehouseId,
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("quantity")] decimal Quantity,
    [property: JsonPropertyName("unit_cost")] decimal? UnitCost,
    [property: JsonPropertyName("serial_units")] IReadOnlyList<ProductEntrySerialUnitRequest>? SerialUnits);

public sealed record ProductEntrySerialUnitRequest(
    [property: JsonPropertyName("serial_type")] string SerialType,
    [property: JsonPropertyName("serial_number")] string SerialNumber);

public sealed record ProductExitStoreRequest(
    [property: JsonPropertyName("reason")] string Reason,
    [property: JsonPropertyName("reference")] string? Reference,
    [property: JsonPropertyName("notes")] string? Notes,
    [property: JsonPropertyName("items")] IReadOnlyList<ProductExitStoreItemRequest> Items);

public sealed record ProductExitStoreItemRequest(
    [property: JsonPropertyName("warehouse_id")] long WarehouseId,
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("quantity")] decimal Quantity,
    [property: JsonPropertyName("product_unit_ids")] IReadOnlyList<long>? ProductUnitIds);

public sealed record ProductMovementCreatedResponse(
    [property: JsonPropertyName("data")] ProductMovementCreatedData Data);

public sealed record ProductMovementCreatedData(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("document_number")] string? DocumentNumber,
    [property: JsonPropertyName("reason")] string Reason,
    [property: JsonPropertyName("status")] string Status);

public sealed record WarehouseListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<InventoryWarehouseOption> Data);

public sealed record InventoryWarehouseOption(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string? Name,
    [property: JsonPropertyName("code")] string? Code,
    [property: JsonPropertyName("branch_name")] string? BranchName,
    [property: JsonPropertyName("status")] string? Status)
{
    public string WarehouseLabel
    {
        get
        {
            string name = string.IsNullOrWhiteSpace(Name) ? "Almacén sin nombre" : Name;
            return string.IsNullOrWhiteSpace(Code) ? name : $"{name} ({Code})";
        }
    }

    public static InventoryWarehouseOption FromStock(InventoryWarehouseStock stock)
    {
        return new InventoryWarehouseOption(
            stock.WarehouseId,
            stock.WarehouseName,
            stock.WarehouseCode,
            stock.BranchName,
            "active");
    }
}

public sealed record ProductApiResponse(
    [property: JsonPropertyName("data")] ProductApiData Data);

public sealed record ProductApiData(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("sku")] string Sku,
    [property: JsonPropertyName("tracking_type")] string TrackingType,
    [property: JsonPropertyName("base_price")] decimal? BasePrice,
    [property: JsonPropertyName("sale_currency")] string SaleCurrency,
    [property: JsonPropertyName("sale_exchange_rate_type_id")] long? SaleExchangeRateTypeId,
    [property: JsonPropertyName("warranty_policy_id")] long? WarrantyPolicyId,
    [property: JsonPropertyName("can_change_tracking_type")] bool? CanChangeTrackingType,
    [property: JsonPropertyName("units_count")] int? UnitsCount,
    [property: JsonPropertyName("is_active")] bool IsActive);

public sealed record ProductSaveRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("sku")] string Sku,
    [property: JsonPropertyName("tracking_type")] string TrackingType,
    [property: JsonPropertyName("base_price")] decimal? BasePrice,
    [property: JsonPropertyName("sale_currency")] string SaleCurrency,
    [property: JsonPropertyName("sale_exchange_rate_type_id")] long? SaleExchangeRateTypeId,
    [property: JsonPropertyName("warranty_policy_id")] long? WarrantyPolicyId,
    [property: JsonPropertyName("is_active")] bool IsActive);

public sealed record InventoryBulkActionRequest(
    [property: JsonPropertyName("product_ids")] IReadOnlyList<long> ProductIds,
    [property: JsonPropertyName("action")] string Action,
    [property: JsonPropertyName("payload")] InventoryBulkActionPayload? Payload);

public sealed record InventoryBulkActionPayload(
    [property: JsonPropertyName("warranty_policy_id")] long? WarrantyPolicyId = null,
    [property: JsonPropertyName("sale_exchange_rate_type_id")] long? SaleExchangeRateTypeId = null,
    [property: JsonPropertyName("price_list_id")] long? PriceListId = null,
    [property: JsonPropertyName("strategy")] string? Strategy = null,
    [property: JsonPropertyName("price")] decimal? Price = null,
    [property: JsonPropertyName("percent")] decimal? Percent = null,
    [property: JsonPropertyName("currency")] string? Currency = null);

public sealed record InventoryBulkActionResponse(
    [property: JsonPropertyName("data")] InventoryBulkActionResult Data);

public sealed record InventoryBulkActionResult(
    [property: JsonPropertyName("action")] string Action,
    [property: JsonPropertyName("requested_count")] int RequestedCount,
    [property: JsonPropertyName("updated_count")] int UpdatedCount,
    [property: JsonPropertyName("skipped_count")] int SkippedCount,
    [property: JsonPropertyName("updated")] IReadOnlyList<InventoryBulkActionProduct> Updated,
    [property: JsonPropertyName("skipped")] IReadOnlyList<InventoryBulkActionSkippedProduct> Skipped);

public sealed record InventoryBulkActionProduct(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("sku")] string Sku);

public sealed record InventoryBulkActionSkippedProduct(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("reason")] string Reason);

public sealed record PriceListListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<PriceListOption> Data);

public sealed record PriceListResponse(
    [property: JsonPropertyName("data")] PriceListOption Data);

public sealed record PriceListOption(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("description")] string? Description,
    [property: JsonPropertyName("is_default")] bool IsDefault,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("sort_order")] int SortOrder)
{
    public string StatusLabel => IsActive ? "Activa" : "Inactiva";

    public string DefaultLabel => IsDefault ? "Sí" : "No";
}

public sealed record PriceListSaveRequest(
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("description")] string? Description,
    [property: JsonPropertyName("is_default")] bool IsDefault,
    [property: JsonPropertyName("is_active")] bool IsActive,
    [property: JsonPropertyName("sort_order")] int SortOrder);

public sealed record ProductPriceListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<ProductPriceOption> Data);

public sealed record ProductPriceOption(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("product_id")] long ProductId,
    [property: JsonPropertyName("price_list_id")] long PriceListId,
    [property: JsonPropertyName("price_list")] PriceListOption? PriceList,
    [property: JsonPropertyName("price")] decimal Price,
    [property: JsonPropertyName("currency")] string Currency,
    [property: JsonPropertyName("exchange_rate_type_id")] long? ExchangeRateTypeId,
    [property: JsonPropertyName("exchange_rate_type")] ExchangeRateTypeOption? ExchangeRateType,
    [property: JsonPropertyName("is_active")] bool IsActive);

public sealed record ProductPricesSyncRequest(
    [property: JsonPropertyName("prices")] IReadOnlyList<ProductPriceSyncItemRequest> Prices);

public sealed record ProductPriceSyncItemRequest(
    [property: JsonPropertyName("price_list_id")] long PriceListId,
    [property: JsonPropertyName("price")] decimal Price,
    [property: JsonPropertyName("currency")] string Currency,
    [property: JsonPropertyName("exchange_rate_type_id")] long? ExchangeRateTypeId,
    [property: JsonPropertyName("is_active")] bool IsActive);

public sealed record ExchangeRateTypeListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<ExchangeRateTypeOption> Data);

public sealed record ExchangeRateTypeOption(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("code")] string Code,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("is_default")] bool IsDefault,
    [property: JsonPropertyName("is_active")] bool IsActive)
{
    public string Label => IsDefault ? $"{Name} ({Code}) - predeterminada" : $"{Name} ({Code})";
}

public sealed record WarrantyPolicyListResponse(
    [property: JsonPropertyName("data")] IReadOnlyList<WarrantyPolicyOption> Data);

public sealed record WarrantyPolicyOption(
    [property: JsonPropertyName("id")] long Id,
    [property: JsonPropertyName("name")] string Name,
    [property: JsonPropertyName("duration_days")] int DurationDays,
    [property: JsonPropertyName("coverage_type")] string CoverageType,
    [property: JsonPropertyName("is_active")] bool IsActive)
{
    public string Label => $"{Name} - {DurationDays} días";
}
