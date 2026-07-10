<?php

namespace App\Modules\InventoryTransfers\Requests;

use App\Modules\InventoryTransfers\Models\InventoryTransferItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveInventoryTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventory_transfer_item_id' => ['required', 'integer'],
            'items.*.action' => ['required', Rule::in([
                InventoryTransferItem::RESOLUTION_INVESTIGATING,
                InventoryTransferItem::RESOLUTION_ACCEPTED_LOSS,
                InventoryTransferItem::RESOLUTION_ADJUSTED_MANUALLY,
            ])],
            'items.*.quantity' => [
                'required_if:items.*.action,'.InventoryTransferItem::RESOLUTION_ADJUSTED_MANUALLY,
                'nullable',
                'numeric',
                'gt:0',
            ],
            'items.*.notes' => ['nullable', 'string', 'max:1000'],
            'items.*.resolved_at' => ['nullable', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'items.required' => 'Debe indicar al menos un item para resolver.',
            'items.*.action.required' => 'Indique la accion de resolucion para el item.',
            'items.*.action.in' => 'La accion de resolucion no es valida.',
            'items.*.quantity.required_if' => 'La cantidad es obligatoria cuando la accion es ajuste manual.',
            'items.*.quantity.gt' => 'La cantidad de ajuste manual debe ser mayor que cero.',
        ];
    }
}
