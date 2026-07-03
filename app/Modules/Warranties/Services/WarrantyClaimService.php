<?php

namespace App\Modules\Warranties\Services;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Warranties\Models\WarrantyClaim;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarrantyClaimService
{
    private const OPEN_STATUSES = [
        WarrantyClaim::STATUS_RECEIVED,
        WarrantyClaim::STATUS_UNDER_REVIEW,
        WarrantyClaim::STATUS_APPROVED,
    ];

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function create(User $user, array $data): WarrantyClaim
    {
        return DB::transaction(function () use ($user, $data): WarrantyClaim {
            $saleItem = SaleItem::query()
                ->with(['sale.customer', 'product'])
                ->lockForUpdate()
                ->findOrFail($data['sale_item_id']);

            $this->assertWarrantyEligible($saleItem);

            $quantity = (float) ($data['quantity'] ?? 1);
            $productUnit = $this->resolveProductUnit($saleItem, $data['product_unit_id'] ?? null, $quantity);
            $this->assertClaimableQuantity($saleItem, $quantity, $productUnit);

            $claim = WarrantyClaim::create([
                'sale_id' => $saleItem->sale_id,
                'sale_item_id' => $saleItem->id,
                'customer_id' => $saleItem->sale->customer_id,
                'product_id' => $saleItem->product_id,
                'product_unit_id' => $productUnit?->id,
                'status' => WarrantyClaim::STATUS_RECEIVED,
                'quantity' => $quantity,
                'customer_name' => $data['customer_name'] ?? $saleItem->sale->customer?->name,
                'customer_phone' => $data['customer_phone'] ?? null,
                'issue_description' => $data['issue_description'],
                'received_notes' => $data['received_notes'] ?? null,
                'received_by' => $user->id,
                'received_at' => now(),
            ]);

            if ($productUnit) {
                $productUnit->update(['status' => ProductUnit::STATUS_WARRANTY_HOLD]);
            }

            $claim = $this->loadClaim($claim);

            $this->audit->record('warranty.claim.received', $claim, $user, null, [
                'sale_id' => $claim->sale_id,
                'sale_item_id' => $claim->sale_item_id,
                'product_id' => $claim->product_id,
                'product_unit_id' => $claim->product_unit_id,
                'status' => $claim->status,
            ]);

            return $claim;
        });
    }

    public function review(WarrantyClaim $claim, User $user, array $data): WarrantyClaim
    {
        if (! in_array($claim->status, [WarrantyClaim::STATUS_RECEIVED, WarrantyClaim::STATUS_UNDER_REVIEW], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden revisar garantias recibidas o en revision.',
            ]);
        }

        $oldValues = [
            'status' => $claim->status,
            'diagnosis' => $claim->diagnosis,
            'resolution_type' => $claim->resolution_type,
        ];

        $claim->update([
            'status' => $data['status'],
            'diagnosis' => $data['diagnosis'] ?? $claim->diagnosis,
            'resolution_type' => $data['resolution_type'] ?? null,
            'resolution_notes' => $data['resolution_notes'] ?? null,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $claim = $this->loadClaim($claim);

        $this->audit->record('warranty.claim.reviewed', $claim, $user, $oldValues, [
            'status' => $claim->status,
            'diagnosis' => $claim->diagnosis,
            'resolution_type' => $claim->resolution_type,
        ]);

        return $claim;
    }

    public function deliver(WarrantyClaim $claim, User $user, ?string $notes = null): WarrantyClaim
    {
        if (! in_array($claim->status, [WarrantyClaim::STATUS_APPROVED, WarrantyClaim::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages([
                'status' => 'Solo se pueden entregar garantias aprobadas o rechazadas.',
            ]);
        }

        $oldStatus = $claim->status;

        $claim->update([
            'status' => WarrantyClaim::STATUS_DELIVERED,
            'resolution_notes' => $notes ?? $claim->resolution_notes,
            'delivered_by' => $user->id,
            'delivered_at' => now(),
        ]);

        $claim = $this->loadClaim($claim);

        $this->audit->record('warranty.claim.delivered', $claim, $user, [
            'status' => $oldStatus,
        ], [
            'status' => $claim->status,
        ]);

        return $claim;
    }

    public function loadClaim(WarrantyClaim $claim): WarrantyClaim
    {
        return $claim->refresh()->load([
            'sale.customer',
            'saleItem.product',
            'product',
            'productUnit',
            'receiver',
            'reviewer',
            'deliverer',
        ]);
    }

    private function assertWarrantyEligible(SaleItem $saleItem): void
    {
        if ($saleItem->sale->status !== Sale::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'sale_item_id' => 'Solo se pueden crear garantias sobre ventas confirmadas.',
            ]);
        }

        if ($saleItem->warranty_policy_id === null || $saleItem->warranty_expires_at === null) {
            throw ValidationException::withMessages([
                'sale_item_id' => 'El item vendido no tiene garantia registrada.',
            ]);
        }

        if ($saleItem->warranty_expires_at->lt(now())) {
            throw ValidationException::withMessages([
                'sale_item_id' => 'La garantia del item vendido ya vencio.',
            ]);
        }
    }

    private function resolveProductUnit(SaleItem $saleItem, ?int $productUnitId, float $quantity): ?ProductUnit
    {
        if (! $saleItem->product->requiresSerializedTracking()) {
            if ($productUnitId !== null) {
                throw ValidationException::withMessages([
                    'product_unit_id' => 'Solo los productos serializados pueden asociar IMEI o serial.',
                ]);
            }

            return null;
        }

        if ($productUnitId === null || $quantity !== 1.0) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La garantia de un producto serializado requiere una unidad especifica.',
            ]);
        }

        $unit = ProductUnit::query()->lockForUpdate()->findOrFail($productUnitId);

        if ((int) $unit->product_id !== (int) $saleItem->product_id) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La unidad no pertenece al producto vendido.',
            ]);
        }

        if (! in_array($unit->status, [ProductUnit::STATUS_SOLD, ProductUnit::STATUS_AVAILABLE], true)) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La unidad no esta en un estado valido para garantia.',
            ]);
        }

        if (! in_array($unit->id, $saleItem->product_unit_ids ?? [], true)) {
            throw ValidationException::withMessages([
                'product_unit_id' => 'La unidad no esta registrada como vendida en este item.',
            ]);
        }

        return $unit;
    }

    private function assertClaimableQuantity(SaleItem $saleItem, float $quantity, ?ProductUnit $productUnit): void
    {
        if ($quantity <= 0 || $quantity > (float) $saleItem->quantity) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad de garantia no es valida para el item vendido.',
            ]);
        }

        $query = WarrantyClaim::query()
            ->where('sale_item_id', $saleItem->id)
            ->whereIn('status', self::OPEN_STATUSES);

        if ($productUnit) {
            if ((clone $query)->where('product_unit_id', $productUnit->id)->exists()) {
                throw ValidationException::withMessages([
                    'product_unit_id' => 'Ya existe una garantia abierta para esta unidad.',
                ]);
            }

            return;
        }

        $openQuantity = (float) $query->sum('quantity');

        if ($openQuantity + $quantity > (float) $saleItem->quantity) {
            throw ValidationException::withMessages([
                'quantity' => 'La cantidad supera lo disponible para garantias abiertas.',
            ]);
        }
    }
}
