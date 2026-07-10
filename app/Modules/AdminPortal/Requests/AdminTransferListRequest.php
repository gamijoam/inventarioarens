<?php

namespace App\Modules\AdminPortal\Requests;

use App\Modules\InventoryTransfers\Models\InventoryTransfer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminTransferListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->can('inventory_transfers.admin');
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'array'],
            'status.*' => ['string', Rule::in(InventoryTransfer::ALL_STATUSES)],
            'warehouse_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        $statuses = $this->input('status', []);

        if (is_string($statuses)) {
            $statuses = array_filter(explode(',', $statuses));
        }

        return [
            'statuses' => array_values(array_unique(array_filter($statuses, fn ($s) => is_string($s) && $s !== ''))),
            'warehouse_id' => $this->filled('warehouse_id') ? (int) $this->input('warehouse_id') : null,
            'date_from' => $this->input('date_from'),
            'date_to' => $this->input('date_to'),
            'search' => trim((string) $this->input('search', '')),
            'limit' => (int) $this->input('limit', 25),
            'page' => (int) $this->input('page', 1),
        ];
    }
}
