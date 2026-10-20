<?php

namespace App\Modules\Inventory\Requests;

use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreInventoryManualMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manual_movements.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'product_id' => ['required', 'exists:products,id'],
            'product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'type' => ['required', 'in:internal_consumption,write_off,adjustment_in,adjustment_out,return_internal,damaged,loss,found'],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'serial_units' => ['nullable', 'array'],
            'serial_units.*.serial_number' => ['required_with:serial_units', 'string', 'max:100'],
            'serial_units.*.serial_type' => ['nullable', 'string', 'in:serial,imei'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $productId = $this->input('product_id');
            if (! $productId) {
                return;
            }

            $product = Product::query()->find($productId);
            if (! $product) {
                return;
            }

            $variantId = $this->input('product_variant_id');
            if ($variantId) {
                $variantBelongs = ProductVariant::query()
                    ->where('id', $variantId)
                    ->where('product_id', $product->id)
                    ->exists();
                if (! $variantBelongs) {
                    $v->errors()->add('product_variant_id', 'La variante no pertenece al producto seleccionado.');
                }
            }

            $requiresSerials = $product->requiresSerializedTracking();
            $quantity = (float) $this->input('quantity', 0);
            $serialUnits = (array) $this->input('serial_units', []);
            $type = (string) $this->input('type');
            $warehouseId = (int) $this->input('warehouse_id');

            $isInput = in_array($type, ['adjustment_in', 'return_internal', 'found'], true);

            if ($requiresSerials) {
                if ($quantity != floor($quantity)) {
                    $v->errors()->add('quantity', 'Los productos serializados deben manejarse en cantidades enteras.');
                    return;
                }

                if (count($serialUnits) !== (int) $quantity) {
                    $v->errors()->add(
                        'serial_units',
                        "Debes indicar exactamente {$quantity} " . ($quantity === 1.0 ? 'IMEI/serial' : 'IMEIs/seriales') . " para este movimiento."
                    );
                    return;
                }

                $numbers = [];
                foreach ($serialUnits as $idx => $u) {
                    $num = trim((string) ($u['serial_number'] ?? ''));
                    if ($num === '') {
                        $v->errors()->add("serial_units.{$idx}.serial_number", 'El número serial o IMEI no puede estar vacío.');
                        continue;
                    }
                    if (in_array(strtolower($num), $numbers, true)) {
                        $v->errors()->add("serial_units.{$idx}.serial_number", "El serial/IMEI {$num} está repetido en la solicitud.");
                    }
                    $numbers[] = strtolower($num);
                }

                if ($v->errors()->isNotEmpty()) {
                    return;
                }

                if ($isInput) {
                    // Entrada: verificar que ninguno de los IMEIs exista ya en estado AVAILABLE en este tenant
                    $existing = ProductUnit::query()
                        ->whereIn('serial_number', array_column($serialUnits, 'serial_number'))
                        ->where('status', ProductUnit::STATUS_AVAILABLE)
                        ->pluck('serial_number')
                        ->all();

                    if ($existing !== []) {
                        $v->errors()->add(
                            'serial_units',
                            'Los siguientes IMEIs/seriales ya existen activos en el inventario: ' . implode(', ', $existing)
                        );
                    }
                } else {
                    // Salida: verificar que cada IMEI exista en ese almacén con estado AVAILABLE
                    $unitsQuery = ProductUnit::query()
                        ->where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('status', ProductUnit::STATUS_AVAILABLE)
                        ->whereIn('serial_number', array_column($serialUnits, 'serial_number'));

                    if ($variantId) {
                        $unitsQuery->where(function ($q) use ($variantId) {
                            $q->where('product_variant_id', $variantId)
                              ->orWhereNull('product_variant_id');
                        });
                    }

                    $foundUnits = $unitsQuery->pluck('serial_number')->map(fn ($s) => strtolower($s))->all();

                    foreach ($serialUnits as $u) {
                        $num = trim((string) ($u['serial_number'] ?? ''));
                        if (! in_array(strtolower($num), $foundUnits, true)) {
                            $v->errors()->add(
                                'serial_units',
                                "El IMEI/serial {$num} no está disponible en el almacén seleccionado."
                            );
                        }
                    }
                }
            }
        });
    }
}
