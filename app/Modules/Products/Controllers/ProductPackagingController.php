<?php

namespace App\Modules\Products\Controllers;

use App\Modules\Products\Models\Product;
use App\Modules\Products\Models\ProductPackaging;
use App\Modules\Products\Resources\ProductResource;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductPackagingController extends Controller
{
    public function index(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->can('products.view'), 403);

        return response()->json([
            'data' => ProductPackaging::query()
                ->where('product_id', $product->id)
                ->orderBy('factor')
                ->get()
                ->map(fn (ProductPackaging $packaging): array => $packaging->toApiArray())
                ->values(),
        ]);
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        abort_unless($request->user()->can('products.update'), 403);

        $data = $this->validated($request, $product);
        $packaging = ProductPackaging::create(['product_id' => $product->id, ...$data]);

        return response()->json(['data' => $packaging->toApiArray()], Response::HTTP_CREATED);
    }

    public function update(Request $request, ProductPackaging $productPackaging): JsonResponse
    {
        abort_unless($request->user()->can('products.update'), 403);

        $data = $this->validated($request, $productPackaging->product, $productPackaging, partial: true);
        $productPackaging->update($data);

        return response()->json(['data' => $productPackaging->refresh()->toApiArray()]);
    }

    public function destroy(Request $request, ProductPackaging $productPackaging): Response
    {
        abort_unless($request->user()->can('products.update'), 403);

        $productPackaging->delete();

        return response()->noContent();
    }

    public function lookup(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('products.view') || $request->user()->can('pos.checkout'), 403);

        $code = trim((string) $request->validate(['code' => ['required', 'string', 'max:120']])['code']);
        $packaging = ProductPackaging::query()
            ->with('product')
            ->where('barcode', $code)
            ->where('is_active', true)
            ->first();

        abort_unless($packaging && $packaging->product, 404, 'No hay una presentacion con ese codigo.');

        return response()->json(['data' => [
            'packaging' => $packaging->toApiArray(),
            'product' => ProductResource::make($packaging->product)->resolve($request),
        ]]);
    }

    private function validated(Request $request, Product $product, ?ProductPackaging $packaging = null, bool $partial = false): array
    {
        $tenantId = app(TenantManager::class)->require()->id;
        $required = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'name' => [$required, 'string', 'max:120', Rule::unique('product_packagings', 'name')->where('tenant_id', $tenantId)->where('product_id', $product->id)->ignore($packaging?->id)],
            'factor' => [$required, 'numeric', 'gt:0'],
            'barcode' => ['nullable', 'string', 'max:120', Rule::unique('product_packagings', 'barcode')->where('tenant_id', $tenantId)->ignore($packaging?->id)],
            'price_usd' => ['nullable', 'numeric', 'gt:0'],
            'product_variant_id' => ['nullable', 'integer', Rule::exists('product_variants', 'id')->where('tenant_id', $tenantId)->where('product_id', $product->id)],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if (! empty($data['barcode']) && Product::query()->where('barcode', $data['barcode'])->exists()) {
            throw ValidationException::withMessages([
                'barcode' => 'Ese codigo de barras ya pertenece a un producto.',
            ]);
        }

        return $data;
    }
}
