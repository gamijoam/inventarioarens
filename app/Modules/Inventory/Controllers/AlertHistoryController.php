<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\AlertHistory;
use App\Modules\Inventory\Requests\ListAlertHistoryRequest;
use App\Modules\Inventory\Resources\AlertHistoryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AlertHistoryController extends Controller
{
    public function index(ListAlertHistoryRequest $request): AnonymousResourceCollection
    {
        $query = AlertHistory::query()
            ->when($request->filled('alert_type'), fn ($q) => $q->where('alert_type', $request->string('alert_type')))
            ->when($request->filled('severity'), fn ($q) => $q->where('severity', $request->string('severity')))
            ->when($request->has('is_dismissed'), function ($q) use ($request) {
                $val = filter_var($request->input('is_dismissed'), FILTER_VALIDATE_BOOLEAN);
                $val ? $q->whereNotNull('dismissed_at') : $q->whereNull('dismissed_at');
            })
            ->when($request->filled('subject_type') && $request->filled('product_id'), function ($q) use ($request) {
                $q->where('subject_type', $request->string('subject_type'))
                    ->where('subject_id', $request->integer('product_id'));
            })
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('detected_at', '>=', $request->date('date_from')))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('detected_at', '<=', $request->date('date_to')))
            ->latest('detected_at');

        return AlertHistoryResource::collection($query->paginate($request->integer('limit', 25)));
    }

    public function show(AlertHistory $alert): AlertHistoryResource
    {
        return AlertHistoryResource::make($alert);
    }

    public function dismiss(Request $request, AlertHistory $alert): JsonResponse
    {
        if ($alert->isDismissed()) {
            abort(409, 'La alerta ya fue descartada.');
        }

        $alert->update([
            'dismissed_at' => now(),
            'dismissed_by' => $request->user()?->id,
        ]);

        return response()->json(['data' => AlertHistoryResource::make($alert->refresh())]);
    }
}
