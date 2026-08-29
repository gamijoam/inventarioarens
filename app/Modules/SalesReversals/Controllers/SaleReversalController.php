<?php

namespace App\Modules\SalesReversals\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosOrder;
use App\Modules\SalesReversals\Requests\ReversePosSaleRequest;
use App\Modules\SalesReversals\Resources\SaleReversalResource;
use App\Modules\SalesReversals\Services\SaleReversalService;

class SaleReversalController extends Controller
{
    public function __invoke(
        ReversePosSaleRequest $request,
        PosOrder $posOrder,
        SaleReversalService $reversals,
    ): SaleReversalResource {
        return SaleReversalResource::make(
            $reversals->reverse($posOrder, $request->user(), $request->validated())
        );
    }
}
