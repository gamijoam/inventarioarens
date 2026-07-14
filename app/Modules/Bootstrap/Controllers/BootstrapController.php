<?php

namespace App\Modules\Bootstrap\Controllers;

use App\Modules\Bootstrap\Requests\BootstrapRequest;
use App\Modules\Bootstrap\Resources\BootstrapResource;
use App\Modules\Bootstrap\Services\BootstrapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class BootstrapController extends Controller
{
    public function __construct(private readonly BootstrapService $bootstrap) {}

    public function store(BootstrapRequest $request): JsonResponse
    {
        $data = $request->validated();

        $this->bootstrap->ensureCanRun(
            $data['bootstrap_token'] ?? $request->header('X-Bootstrap-Token'),
            $request,
        );

        $result = $this->bootstrap->run($data, $request);

        return BootstrapResource::make($result)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
