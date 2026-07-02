<?php

namespace App\Modules\Kardex\Controllers;

use App\Modules\Kardex\Requests\KardexProductRequest;
use App\Modules\Kardex\Services\KardexService;
use App\Modules\Products\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class KardexController extends Controller
{
    public function product(KardexProductRequest $request, Product $product, KardexService $kardex): JsonResponse
    {
        return response()->json([
            'data' => $kardex->product($product, $request->validated()),
        ]);
    }
}
