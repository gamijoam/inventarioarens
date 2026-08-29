<?php

namespace App\Modules\InventoryCenter\Requests;

use App\Modules\InventoryCenter\Models\ProductBulkOperation;
use App\Modules\Products\Models\Product;
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

    public const ACTION_FILL_MISSING_PRICE_LIST = 'fill_missing_price_list';

    public const ACTION_UPDATE_PRICE_LIST = 'update_price_list';

    public const ACTION_ASSIGN_FISCAL_TAX_RATE = ProductBulkOperation::ACTION_ASSIGN_FISCAL_TAX_RATE;

    public const PRICE_STRATEGY_BASE_PRICE = 'base_price';

    public const PRICE_STRATEGY_FIXED_PRICE = 'fixed_price';

    public const PRICE_STRATEGY_PERCENT_OVER_BASE = 'percent_over_base';

    public function authorize(): bool
    {
        return $this->user()?->can('products.update') === true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantManager::class)->current()?->id ?? app(TenantManager::class)->require()->id;
        $tenantIds = [$tenantId];

        return [
            'product_ids' => ['nullable', 'array', 'min:1', 'max:200'],
            'product_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('products', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'action' => [
                'required',
                'string',
                Rule::in([
                    self::ACTION_ACTIVATE,
                    self::ACTION_DEACTIVATE,
                    self::ACTION_ASSIGN_WARRANTY_POLICY,
                    self::ACTION_ASSIGN_EXCHANGE_RATE_TYPE,
                    self::ACTION_FILL_MISSING_PRICE_LIST,
                    self::ACTION_UPDATE_PRICE_LIST,
                    self::ACTION_ASSIGN_FISCAL_TAX_RATE,
                ]),
            ],
            'all_matching' => ['sometimes', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.search' => ['nullable', 'string', 'max:120'],
            'filters.tracking_type' => ['nullable', Rule::in([
                Product::TRACKING_QUANTITY,
                Product::TRACKING_SERIALIZED,
            ])],
            'filters.active_status' => ['nullable', Rule::in(['active', 'inactive', 'all'])],
            'filters.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->whereIn('tenant_id', $tenantIds)],
            'filters.category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->whereIn('tenant_id', $tenantIds)],
            'filters.tag_id' => ['nullable', 'integer', Rule::exists('tags', 'id')->whereIn('tenant_id', $tenantIds)],
            'payload' => ['nullable', 'array'],
            'payload.price_list_id' => [
                'nullable',
                'integer',
                Rule::exists('price_lists', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'payload.strategy' => [
                'nullable',
                'string',
                Rule::in([
                    self::PRICE_STRATEGY_BASE_PRICE,
                    self::PRICE_STRATEGY_FIXED_PRICE,
                    self::PRICE_STRATEGY_PERCENT_OVER_BASE,
                ]),
            ],
            'payload.price' => ['nullable', 'numeric', 'gte:0'],
            'payload.percent' => ['nullable', 'numeric', 'min:-99', 'max:10000'],
            'payload.currency' => ['nullable', 'string', 'size:3', Rule::in(['USD', 'VES'])],
            'payload.warranty_policy_id' => [
                'nullable',
                'integer',
                Rule::exists('warranty_policies', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'payload.sale_exchange_rate_type_id' => [
                'nullable',
                'integer',
                Rule::exists('exchange_rate_types', 'id')->whereIn('tenant_id', $tenantIds),
            ],
            'payload.fiscal_tax_rate_id' => [
                'nullable',
                'integer',
                Rule::exists('fiscal_tax_rates', 'id')
                    ->whereIn('tenant_id', $tenantIds)
                    ->where('is_active', true),
            ],
            'payload.overwrite_existing' => ['sometimes', 'boolean'],
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

                if ($this->boolean('all_matching')) {
                    if ($action !== self::ACTION_ASSIGN_FISCAL_TAX_RATE) {
                        $validator->errors()->add('all_matching', 'La selección de todos los resultados solo está disponible para clasificación fiscal.');
                    }

                    if ($this->filled('product_ids')) {
                        $validator->errors()->add('product_ids', 'No combines productos específicos con todos los resultados filtrados.');
                    }
                } elseif (! is_array($this->input('product_ids')) || count($this->input('product_ids')) < 1) {
                    $validator->errors()->add('product_ids', 'Selecciona al menos un producto.');
                }

                if (
                    $action === self::ACTION_ASSIGN_FISCAL_TAX_RATE
                    && ! $this->filled('payload.fiscal_tax_rate_id')
                ) {
                    $validator->errors()->add('payload.fiscal_tax_rate_id', 'Selecciona el tratamiento fiscal a asignar.');
                }

                if (! in_array($action, [self::ACTION_FILL_MISSING_PRICE_LIST, self::ACTION_UPDATE_PRICE_LIST], true)) {
                    return;
                }

                if (! $this->filled('payload.price_list_id')) {
                    $validator->errors()->add('payload.price_list_id', 'Selecciona la lista de precio.');
                }

                $strategy = $this->input('payload.strategy');
                if (! $strategy) {
                    $validator->errors()->add('payload.strategy', 'Selecciona la estrategia para calcular precios.');
                }

                if ($strategy === self::PRICE_STRATEGY_FIXED_PRICE && ! $this->filled('payload.price')) {
                    $validator->errors()->add('payload.price', 'Indica el monto fijo a aplicar.');
                }

                if ($strategy === self::PRICE_STRATEGY_PERCENT_OVER_BASE && ! $this->filled('payload.percent')) {
                    $validator->errors()->add('payload.percent', 'Indica el porcentaje sobre el precio base.');
                }

                if (! $this->filled('payload.currency')) {
                    $validator->errors()->add('payload.currency', 'Selecciona la moneda del precio.');
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
            'payload.fiscal_tax_rate_id.exists' => 'El tratamiento fiscal seleccionado no pertenece a la empresa actual.',
            'filters.brand_id.exists' => 'La marca seleccionada no pertenece a la empresa actual.',
            'filters.category_id.exists' => 'La categoría seleccionada no pertenece a la empresa actual.',
            'filters.tag_id.exists' => 'La etiqueta seleccionada no pertenece a la empresa actual.',
            'payload.warranty_policy_id.exists' => 'La política de garantía seleccionada no pertenece a la empresa actual.',
            'payload.price_list_id.exists' => 'La lista de precio seleccionada no pertenece a la empresa actual.',
            'payload.strategy.in' => 'La estrategia de precio seleccionada no es válida.',
            'payload.price.numeric' => 'El precio debe ser numérico.',
            'payload.price.gte' => 'El precio no puede ser negativo.',
            'payload.percent.numeric' => 'El porcentaje debe ser numérico.',
            'payload.percent.min' => 'El porcentaje no puede ser menor a -99%.',
            'payload.percent.max' => 'El porcentaje es demasiado alto.',
            'payload.currency.in' => 'La moneda del precio debe ser USD o VES.',
            'payload.currency.size' => 'La moneda del precio debe tener 3 caracteres.',
            'payload.sale_exchange_rate_type_id.exists' => 'El tipo de tasa seleccionado no pertenece a la empresa actual.',
        ];
    }
}
