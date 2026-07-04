<?php

namespace App\Modules\InventoryCenter\Requests;

use App\Modules\Products\Models\ProductAudit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryCenterProductAuditsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.view') === true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', Rule::in([
                'all',
                ProductAudit::ACTION_CREATED,
                ProductAudit::ACTION_UPDATED,
                ProductAudit::ACTION_DEACTIVATED,
            ])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
