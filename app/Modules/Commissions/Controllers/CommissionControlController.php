<?php

namespace App\Modules\Commissions\Controllers;

use App\Modules\Commissions\Requests\CommissionControlRequest;
use App\Modules\Commissions\Services\CommissionControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class CommissionControlController extends Controller
{
    public function __invoke(CommissionControlRequest $request, CommissionControlService $control): JsonResponse
    {
        $user = $request->user();
        $ownOnly = ! $user->can('commissions.view_all');

        return response()->json($control->report(
            $request->validated(),
            $user,
            $ownOnly,
        ));
    }
}
