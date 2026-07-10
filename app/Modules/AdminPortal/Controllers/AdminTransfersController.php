<?php

namespace App\Modules\AdminPortal\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AdminPortal\Requests\AdminTransferListRequest;
use App\Modules\AdminPortal\Services\AdminTransferService;
use Illuminate\Http\JsonResponse;

class AdminTransfersController extends Controller
{
    public function index(AdminTransferListRequest $request, AdminTransferService $transfers): JsonResponse
    {
        return response()->json([
            'data' => $transfers->index($request->filters()),
        ]);
    }

    public function summary(AdminTransferListRequest $request, AdminTransferService $transfers): JsonResponse
    {
        return response()->json([
            'data' => $transfers->summary($request->filters()),
        ]);
    }
}
