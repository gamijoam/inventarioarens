<?php

namespace App\Modules\Sync\Services;

use App\Modules\Products\Models\Product;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncInitialSnapshotService
{
    public function queueForNode(Tenant $tenant, int $targetNodeId, string $installationCode): array
    {
        $this->clearPreviousSnapshot($tenant, $targetNodeId, $installationCode);

        $summary = [
            'branch.created' => $this->queueBranches($tenant, $targetNodeId, $installationCode),
            'warehouse.created' => $this->queueWarehouses($tenant, $targetNodeId, $installationCode),
            'exchange_rate_type.created' => $this->queueExchangeRateTypes($tenant, $targetNodeId, $installationCode),
            'exchange_rate.created' => $this->queueExchangeRates($tenant, $targetNodeId, $installationCode),
            'payment_method.created' => $this->queuePaymentMethods($tenant, $targetNodeId, $installationCode),
            'brand.created' => $this->queueBrands($tenant, $targetNodeId, $installationCode),
            'category.created' => $this->queueCategories($tenant, $targetNodeId, $installationCode),
            'tag.created' => $this->queueTags($tenant, $targetNodeId, $installationCode),
            'warranty_policy.created' => $this->queueWarrantyPolicies($tenant, $targetNodeId, $installationCode),
            'supplier.created' => $this->queueSuppliers($tenant, $targetNodeId, $installationCode),
            'price_list.created' => $this->queuePriceLists($tenant, $targetNodeId, $installationCode),
            'product.created' => $this->queueProducts($tenant, $targetNodeId, $installationCode),
            'product_price.created' => $this->queueProductPrices($tenant, $targetNodeId, $installationCode),
            'promotion.created' => $this->queuePromotions($tenant, $targetNodeId, $installationCode),
            'customer.created' => $this->queueCustomers($tenant, $targetNodeId, $installationCode),
            'stock_movement.created' => $this->queueStockMovements($tenant, $targetNodeId, $installationCode),
            'product_unit.created' => $this->queueProductUnits($tenant, $targetNodeId, $installationCode),
            'product.image.uploaded' => $this->queueProductImages($tenant, $targetNodeId, $installationCode),
            'cash_register.created' => $this->queueCashRegisters($tenant, $targetNodeId, $installationCode),
        ];

        return [
            'queued' => array_sum($summary),
            'events' => $summary,
        ];
    }

    private function clearPreviousSnapshot(Tenant $tenant, int $targetNodeId, string $installationCode): void
    {
        DB::table('sync_outbox')
            ->where('tenant_id', $tenant->id)
            ->where('target_node_id', $targetNodeId)
            ->where('idempotency_key', 'like', 'initial-snapshot:%')
            ->delete();
    }

    private function queueBranches(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('branches')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($branches) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($branches as $branch) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'branch.created', 'branch', (int) $branch->id, [
                        'code' => $branch->code,
                        'name' => $branch->name,
                        'status' => $branch->status,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueWarehouses(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('warehouses')
            ->join('branches', function ($join): void {
                $join->on('branches.id', '=', 'warehouses.branch_id')
                    ->on('branches.tenant_id', '=', 'warehouses.tenant_id');
            })
            ->where('warehouses.tenant_id', $tenant->id)
            ->orderBy('warehouses.id')
            ->select('warehouses.*', 'branches.code as branch_code')
            ->chunkById(200, function ($warehouses) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($warehouses as $warehouse) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'warehouse.created', 'warehouse', (int) $warehouse->id, [
                        'code' => $warehouse->code,
                        'name' => $warehouse->name,
                        'status' => $warehouse->status,
                        'branch_code' => $warehouse->branch_code,
                    ]);
                    $count++;
                }
            }, 'warehouses.id', 'id');

        return $count;
    }

    private function queueExchangeRateTypes(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('exchange_rate_types')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($types) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($types as $type) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'exchange_rate_type.created', 'exchange_rate_type', (int) $type->id, [
                        'code' => $type->code,
                        'name' => $type->name,
                        'is_default' => (bool) $type->is_default,
                        'is_active' => (bool) $type->is_active,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueExchangeRates(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('exchange_rates')
            ->join('exchange_rate_types', function ($join): void {
                $join->on('exchange_rate_types.id', '=', 'exchange_rates.exchange_rate_type_id')
                    ->on('exchange_rate_types.tenant_id', '=', 'exchange_rates.tenant_id');
            })
            ->where('exchange_rates.tenant_id', $tenant->id)
            ->orderBy('exchange_rates.id')
            ->select('exchange_rates.*', 'exchange_rate_types.code as exchange_rate_type_code')
            ->chunkById(200, function ($rates) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($rates as $rate) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'exchange_rate.created', 'exchange_rate', (int) $rate->id, [
                        'exchange_rate_type_code' => $rate->exchange_rate_type_code,
                        'base_currency' => $rate->base_currency,
                        'quote_currency' => $rate->quote_currency,
                        'rate' => (string) $rate->rate,
                        'effective_at' => Carbon::parse($rate->effective_at)->toISOString(),
                        'source' => $rate->source,
                        'is_active' => (bool) $rate->is_active,
                    ]);
                    $count++;
                }
            }, 'exchange_rates.id', 'id');

        return $count;
    }

    private function queuePaymentMethods(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('payment_methods')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($methods) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($methods as $method) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'payment_method.created', 'payment_method', (int) $method->id, [
                        'code' => $method->code,
                        'name' => $method->name,
                        'method' => $method->method,
                        'currency_mode' => $method->currency_mode,
                        'requires_reference' => (bool) $method->requires_reference,
                        'is_active' => (bool) $method->is_active,
                        'sort_order' => (int) $method->sort_order,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queuePriceLists(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('price_lists')
            ->where('tenant_id', $tenant->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->chunkById(200, function ($lists) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($lists as $list) {
                    $paymentRateTypeCode = $list->payment_exchange_rate_type_id
                        ? DB::table('exchange_rate_types')
                            ->where('tenant_id', $tenant->id)
                            ->where('id', $list->payment_exchange_rate_type_id)
                            ->value('code')
                        : null;
                    $methodCodes = DB::table('price_list_payment_method')
                        ->join('payment_methods', function ($join): void {
                            $join->on('payment_methods.id', '=', 'price_list_payment_method.payment_method_id')
                                ->on('payment_methods.tenant_id', '=', 'price_list_payment_method.tenant_id');
                        })
                        ->where('price_list_payment_method.tenant_id', $tenant->id)
                        ->where('price_list_payment_method.price_list_id', $list->id)
                        ->pluck('payment_methods.code')
                        ->all();

                    $this->record($tenant, $targetNodeId, $installationCode, 'price_list.created', 'price_list', (int) $list->id, [
                        'code' => $list->code,
                        'name' => $list->name,
                        'description' => $list->description,
                        'markup_percentage' => $list->markup_percentage === null ? null : (string) $list->markup_percentage,
                        'is_default' => (bool) $list->is_default,
                        'is_active' => (bool) $list->is_active,
                        'sort_order' => (int) $list->sort_order,
                        'payment_exchange_rate_type_code' => $paymentRateTypeCode,
                        'payment_method_codes' => $methodCodes,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueBrands(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;
        DB::table('brands')->where('tenant_id', $tenant->id)->orderBy('id')
            ->chunkById(200, function ($brands) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($brands as $brand) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'brand.created', 'brand', (int) $brand->id, [
                        'slug' => $brand->slug, 'name' => $brand->name, 'description' => $brand->description,
                        'is_active' => (bool) $brand->is_active,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueCategories(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;
        DB::table('categories as categories')
            ->leftJoin('categories as parents', function ($join): void {
                $join->on('parents.id', '=', 'categories.parent_id')
                    ->on('parents.tenant_id', '=', 'categories.tenant_id');
            })
            ->where('categories.tenant_id', $tenant->id)
            ->orderBy('categories.id')
            ->select('categories.*', 'parents.slug as parent_slug')
            ->chunkById(200, function ($categories) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($categories as $category) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'category.created', 'category', (int) $category->id, [
                        'slug' => $category->slug, 'name' => $category->name, 'description' => $category->description,
                        'sort_order' => (int) $category->sort_order, 'is_active' => (bool) $category->is_active,
                        'parent_slug' => $category->parent_slug,
                    ]);
                    $count++;
                }
            }, 'categories.id', 'id');

        return $count;
    }

    private function queueTags(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;
        DB::table('tags')->where('tenant_id', $tenant->id)->orderBy('id')
            ->chunkById(200, function ($tags) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($tags as $tag) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'tag.created', 'tag', (int) $tag->id, [
                        'slug' => $tag->slug, 'name' => $tag->name, 'color' => $tag->color,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueWarrantyPolicies(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('warranty_policies')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($policies) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($policies as $policy) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'warranty_policy.created', 'warranty_policy', (int) $policy->id, [
                        'name' => $policy->name,
                        'duration_days' => (int) $policy->duration_days,
                        'coverage_type' => $policy->coverage_type,
                        'conditions' => $policy->conditions,
                        'is_active' => (bool) $policy->is_active,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueSuppliers(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('suppliers')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($suppliers) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($suppliers as $supplier) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'supplier.created', 'supplier', (int) $supplier->id, [
                        'name' => $supplier->name,
                        'document_type' => $supplier->document_type,
                        'document_number' => $supplier->document_number,
                        'phone' => $supplier->phone,
                        'email' => $supplier->email,
                        'fiscal_address' => $supplier->fiscal_address,
                        'notes' => $supplier->notes,
                        'is_active' => (bool) $supplier->is_active,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueProducts(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('products')
            ->leftJoin('exchange_rate_types', function ($join): void {
                $join->on('exchange_rate_types.id', '=', 'products.sale_exchange_rate_type_id')
                    ->on('exchange_rate_types.tenant_id', '=', 'products.tenant_id');
            })
            ->where('products.tenant_id', $tenant->id)
            ->orderBy('products.id')
            ->select('products.*', 'exchange_rate_types.code as sale_exchange_rate_type_code')
            ->chunkById(200, function ($products) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($products as $product) {
                    $brandSlug = DB::table('brands')->where('tenant_id', $tenant->id)->where('id', $product->brand_id)->value('slug');
                    $categorySlugs = DB::table('product_category')
                        ->join('categories', function ($join): void {
                            $join->on('categories.id', '=', 'product_category.category_id')
                                ->on('categories.tenant_id', '=', 'product_category.tenant_id');
                        })
                        ->where('product_category.tenant_id', $tenant->id)
                        ->where('product_category.product_id', $product->id)
                        ->pluck('categories.slug')
                        ->values()
                        ->all();
                    $tagSlugs = DB::table('product_tag')
                        ->join('tags', function ($join): void {
                            $join->on('tags.id', '=', 'product_tag.tag_id')
                                ->on('tags.tenant_id', '=', 'product_tag.tenant_id');
                        })
                        ->where('product_tag.tenant_id', $tenant->id)
                        ->where('product_tag.product_id', $product->id)
                        ->pluck('tags.slug')
                        ->values()
                        ->all();

                    $this->record($tenant, $targetNodeId, $installationCode, 'product.created', 'product', (int) $product->id, [
                        'sku' => $product->sku,
                        'name' => $product->name,
                        'barcode' => $product->barcode,
                        'description' => $product->description,
                        'long_description' => $product->long_description,
                        'tracking_type' => $product->tracking_type,
                        'unit_of_measure' => $product->unit_of_measure,
                        'track_stock' => (bool) $product->track_stock,
                        'base_price' => $product->base_price === null ? null : (string) $product->base_price,
                        'profit_margin' => $product->profit_margin === null ? null : (string) $product->profit_margin,
                        'pricing_mode' => $product->pricing_mode ?? Product::PRICING_AUTOMATIC,
                        'sale_currency' => $product->sale_currency,
                        'sale_exchange_rate_type_code' => $product->sale_exchange_rate_type_code,
                        'image_url' => $product->image_url,
                        'brand_slug' => $brandSlug,
                        'category_slugs' => $categorySlugs,
                        'tag_slugs' => $tagSlugs,
                        'warranty_policy_id' => $product->warranty_policy_id,
                        'min_stock' => $product->min_stock === null ? null : (string) $product->min_stock,
                        'max_stock' => $product->max_stock === null ? null : (string) $product->max_stock,
                        'reorder_quantity' => $product->reorder_quantity === null ? null : (string) $product->reorder_quantity,
                        'is_catalog_active' => (bool) ($product->is_catalog_active ?? true),
                        'is_active' => (bool) $product->is_active,
                    ]);
                    $count++;
                }
            }, 'products.id', 'id');

        return $count;
    }

    private function queuePromotions(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('promotions')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($promotions) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($promotions as $promotion) {
                    $items = DB::table('promotion_items')
                        ->join('products', function ($join): void {
                            $join->on('products.id', '=', 'promotion_items.product_id')
                                ->on('products.tenant_id', '=', 'promotion_items.tenant_id');
                        })
                        ->where('promotion_items.tenant_id', $tenant->id)
                        ->where('promotion_items.promotion_id', $promotion->id)
                        ->orderBy('promotion_items.sort_order')
                        ->get([
                            'products.sku as product_sku',
                            'promotion_items.quantity',
                            'promotion_items.item_role',
                            'promotion_items.sort_order',
                        ])
                        ->map(fn ($item): array => [
                            'product_sku' => $item->product_sku,
                            'quantity' => (string) $item->quantity,
                            'item_role' => $item->item_role,
                            'sort_order' => (int) $item->sort_order,
                        ])
                        ->values()
                        ->all();

                    $this->record($tenant, $targetNodeId, $installationCode, 'promotion.created', 'promotion', (int) $promotion->id, [
                        'id' => (int) $promotion->id,
                        'name' => $promotion->name,
                        'code' => $promotion->code,
                        'benefit_type' => $promotion->benefit_type,
                        'price_currency' => $promotion->price_currency,
                        'price_usd' => $promotion->price_usd === null ? null : (string) $promotion->price_usd,
                        'discount_percent' => $promotion->discount_percent === null ? null : (string) $promotion->discount_percent,
                        'discount_amount_usd' => $promotion->discount_amount_usd === null ? null : (string) $promotion->discount_amount_usd,
                        'priority' => (int) $promotion->priority,
                        'is_active' => (bool) $promotion->is_active,
                        'starts_at' => $promotion->starts_at,
                        'ends_at' => $promotion->ends_at,
                        'items' => $items,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueProductPrices(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('product_prices')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'product_prices.product_id')
                    ->on('products.tenant_id', '=', 'product_prices.tenant_id');
            })
            ->join('price_lists', function ($join): void {
                $join->on('price_lists.id', '=', 'product_prices.price_list_id')
                    ->on('price_lists.tenant_id', '=', 'product_prices.tenant_id');
            })
            ->leftJoin('exchange_rate_types', function ($join): void {
                $join->on('exchange_rate_types.id', '=', 'product_prices.exchange_rate_type_id')
                    ->on('exchange_rate_types.tenant_id', '=', 'product_prices.tenant_id');
            })
            ->where('product_prices.tenant_id', $tenant->id)
            ->orderBy('product_prices.id')
            ->select('product_prices.*', 'products.sku', 'price_lists.code as price_list_code', 'exchange_rate_types.code as exchange_rate_type_code')
            ->chunkById(200, function ($prices) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($prices as $price) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'product_price.created', 'product_price', (int) $price->id, [
                        'sku' => $price->sku,
                        'price_list_code' => $price->price_list_code,
                        'price' => (string) $price->price,
                        'currency' => $price->currency,
                        'exchange_rate_type_code' => $price->exchange_rate_type_code,
                        'is_active' => (bool) $price->is_active,
                    ]);
                    $count++;
                }
            }, 'product_prices.id', 'id');

        return $count;
    }

    private function queueCustomers(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('customers')
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->chunkById(200, function ($customers) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($customers as $customer) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'customer.created', 'customer', (int) $customer->id, [
                        'name' => $customer->name,
                        'document_type' => $customer->document_type,
                        'document_number' => $customer->document_number,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                        'fiscal_address' => $customer->fiscal_address,
                        'is_generic' => (bool) $customer->is_generic,
                        'is_active' => (bool) $customer->is_active,
                    ]);
                    $count++;
                }
            });

        return $count;
    }

    private function queueStockMovements(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('stock_movements')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'stock_movements.product_id')
                    ->on('products.tenant_id', '=', 'stock_movements.tenant_id');
            })
            ->join('warehouses', function ($join): void {
                $join->on('warehouses.id', '=', 'stock_movements.warehouse_id')
                    ->on('warehouses.tenant_id', '=', 'stock_movements.tenant_id');
            })
            ->where('stock_movements.tenant_id', $tenant->id)
            ->orderBy('stock_movements.id')
            ->select('stock_movements.*', 'products.sku', 'warehouses.code as warehouse_code')
            ->chunkById(200, function ($movements) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($movements as $movement) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'stock_movement.created', 'stock_movement', (int) $movement->id, [
                        'source_id' => (int) $movement->id,
                        'sku' => $movement->sku,
                        'warehouse_code' => $movement->warehouse_code,
                        'type' => $movement->type,
                        'quantity' => (string) $movement->quantity,
                        'unit_cost' => $movement->unit_cost === null ? null : (string) $movement->unit_cost,
                        'reason' => $movement->reason,
                        'reference_type' => $movement->reference_type,
                        'reference_id' => $movement->reference_id,
                        'created_at' => Carbon::parse($movement->created_at)->toISOString(),
                    ]);
                    $count++;
                }
            }, 'stock_movements.id', 'id');

        return $count;
    }

    private function queueProductUnits(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('product_units')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'product_units.product_id')
                    ->on('products.tenant_id', '=', 'product_units.tenant_id');
            })
            ->leftJoin('warehouses', function ($join): void {
                $join->on('warehouses.id', '=', 'product_units.warehouse_id')
                    ->on('warehouses.tenant_id', '=', 'product_units.tenant_id');
            })
            ->where('product_units.tenant_id', $tenant->id)
            ->orderBy('product_units.id')
            ->select('product_units.*', 'products.sku', 'warehouses.code as warehouse_code')
            ->chunkById(200, function ($units) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($units as $unit) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'product_unit.created', 'product_unit', (int) $unit->id, [
                        'sku' => $unit->sku,
                        'warehouse_code' => $unit->warehouse_code,
                        'serial_type' => $unit->serial_type,
                        'serial_number' => $unit->serial_number,
                        'status' => $unit->status,
                    ]);
                    $count++;
                }
            }, 'product_units.id', 'id');

        return $count;
    }

    private function queueProductImages(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('product_images')
            ->join('products', function ($join): void {
                $join->on('products.id', '=', 'product_images.product_id')
                    ->on('products.tenant_id', '=', 'product_images.tenant_id');
            })
            ->leftJoin('product_image_variants', 'product_image_variants.product_image_id', '=', 'product_images.id')
            ->whereNull('product_images.deleted_at')
            ->where('product_images.tenant_id', $tenant->id)
            ->orderBy('product_images.id')
            ->select(
                'product_images.*',
                'products.sku as product_sku',
                'product_image_variants.variant',
                'product_image_variants.storage_path as variant_storage_path',
                'product_image_variants.mime as variant_mime',
                'product_image_variants.size as variant_size',
                'product_image_variants.width as variant_width',
                'product_image_variants.height as variant_height',
            )
            ->chunkById(200, function ($rows) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                $grouped = $rows->groupBy('id');

                foreach ($grouped as $imageRows) {
                    $first = $imageRows->first();
                    $cloudBase = rtrim((string) (config('services.sync.public_base') ?: config('app.url')), '/');

                    $variantMap = [];
                    foreach ($imageRows as $row) {
                        if ($row->variant === null) {
                            continue;
                        }
                        $variantMap[$row->variant] = [
                            'cloud_url' => "{$cloudBase}/storage/{$row->variant_storage_path}",
                            'size' => (int) $row->variant_size,
                            'mime' => $row->variant_mime,
                            'width' => (int) $row->variant_width,
                            'height' => (int) $row->variant_height,
                        ];
                    }

                    $this->record($tenant, $targetNodeId, $installationCode, 'product.image.uploaded', 'product_image', (int) $first->id, [
                        'uuid' => $first->uuid,
                        'product_sku' => $first->product_sku,
                        'product_id' => $first->product_id,
                        'cloud_url' => "{$cloudBase}/storage/{$first->storage_path}",
                        'mime' => $first->mime,
                        'size' => (int) $first->size,
                        'width' => (int) $first->width,
                        'height' => (int) $first->height,
                        'sha256' => $first->sha256,
                        'alt' => $first->alt,
                        'sort' => (int) $first->sort,
                        'is_primary' => (bool) $first->is_primary,
                        'variants' => $variantMap,
                    ]);
                    $count++;
                }
            }, 'product_images.id', 'id');

        return $count;
    }

    private function queueCashRegisters(Tenant $tenant, int $targetNodeId, string $installationCode): int
    {
        $count = 0;

        DB::table('cash_registers')
            ->join('branches', function ($join): void {
                $join->on('branches.id', '=', 'cash_registers.branch_id')
                    ->on('branches.tenant_id', '=', 'cash_registers.tenant_id');
            })
            ->where('cash_registers.tenant_id', $tenant->id)
            ->orderBy('cash_registers.id')
            ->select('cash_registers.*', 'branches.code as branch_code')
            ->chunkById(200, function ($registers) use ($tenant, $targetNodeId, $installationCode, &$count): void {
                foreach ($registers as $register) {
                    $this->record($tenant, $targetNodeId, $installationCode, 'cash_register.created', 'cash_register', (int) $register->id, [
                        'code' => $register->code,
                        'name' => $register->name,
                        'status' => $register->status,
                        'notes' => $register->notes,
                        'branch_code' => $register->branch_code,
                    ]);
                    $count++;
                }
            }, 'cash_registers.id', 'id');

        return $count;
    }

    private function record(
        Tenant $tenant,
        int $targetNodeId,
        string $installationCode,
        string $eventType,
        string $aggregateType,
        int $aggregateId,
        array $payload,
    ): void {
        app(TenantManager::class)->set($tenant);

        DB::table('sync_outbox')->insert([
            'tenant_id' => $tenant->id,
            'event_uuid' => (string) Str::uuid(),
            'origin_node_id' => null,
            'target_node_id' => $targetNodeId,
            'target_scope' => 'node',
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'payload' => json_encode($payload),
            'occurred_at' => now(),
            'available_at' => now(),
            'status' => 'pending',
            'idempotency_key' => $this->snapshotPrefix($installationCode, $targetNodeId).$eventType.':'.$aggregateType.':'.$aggregateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function snapshotPrefix(string $installationCode, int $targetNodeId): string
    {
        return 'initial-snapshot:'.$installationCode.':node-'.$targetNodeId.':';
    }
}
