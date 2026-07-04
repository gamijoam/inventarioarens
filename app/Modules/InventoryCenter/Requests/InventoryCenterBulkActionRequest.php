<?php

namespace App\Modules\InventoryCenter\Requests;

use App\Support\Tenancy\TenantManager;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InventoryCenterBulkActionRequest extends FormRequest
{
    public const ACTION_ACTIVATE = 'activate';
    public const ACTION_DEACTIVATE = 'deactivate';
    public const ACTION_ASSIGN_WARRANTY_POLICY = 'assign_warranty_policy';
    public const ACTION_ASSIGN_EXCHANGE_RATE_TYPE = 'assign_exchange_rate_type';

    public function authorize(): bool
    {
        return $this->user()?->can('products.update') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->require()->id;

        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:200'],
            'product_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where('tenant_id', $tenantId),
            ],
            'action' => [
                'required',
                'string',
                Rule::in([
                    self::ACTION_ACTIVATE,
                    self::ACTION_DEACTIVATE,
                    self::ACTION_ASSIGN_WARRANTY_POLICY,
                    self::ACTION_ASSIGN_EXCHANGE_RATE_TYPE,
                ]),
            ],
            'payload' => ['nullable', 'array'],
            'payload.warranty_policy_id' => [
                'nullable',
                'integer',
                Rule::exists('warranty_policies', 'id')->where('tenant_id', $tenantId),
            ],
            'payload.sale_exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $action = $this->input('action');

                if (
                    $action === self::ACTION_ASSIGN_WARRANTY_POLICY
                    && ! $this->filled('payload.warranty_policy_id')
                ) {
                    $validator->errors()->add('payload.warranty_policy_id', 'Selecciona la política de garantía a asignar.');
                }

                if (
                    $action === self::ACTION_ASSIGN_EXCHANGE_RATE_TYPE
                    && ! $this->filled('payload.sale_exchange_rate_type_id')
                ) {
                    $validator->errors()->add('payload.sale_exchange_rate_type_id', 'Selecciona el tipo de tasa a asignar.');
                }
            },
        ];
    }

    public function messages(): array
    {
        return [
            'product_ids.required' => 'Selecciona al menos un producto.',
            'product_ids.array' => 'La selección de productos no es válida.',
            'product_ids.min' => 'Selecciona al menos un producto.',
            'product_ids.max' => 'No se pueden modificar más de 200 productos a la vez.',
            'product_ids.*.exists' => 'Uno de los productos seleccionados no pertenece a la empresa actual.',
            'product_ids.*.distinct' => 'La selección contiene productos repetidos.',
            'action.required' => 'Selecciona una acción masiva.',
            'action.in' => 'La acción masiva seleccionada no es válida.',
            'payload.warranty_policy_id.exists' => 'La política de garantía seleccionada no pertenece a la empresa actual.',
            'payload.sale_exchange_rate_type_id.exists' => 'El tipo de tasa seleccionado no pertenece a la empresa actual.',
        ];
    }
}
