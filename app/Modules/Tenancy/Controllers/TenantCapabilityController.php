<?php

namespace App\Modules\Tenancy\Controllers;

use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\UpdateTenantCapabilitiesRequest;
use App\Modules\Tenancy\Services\TenantCapabilityService;
use App\Support\Capabilities\BaseCapabilities;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class TenantCapabilityController extends Controller
{
    public function __construct(private readonly TenantCapabilityService $capabilities) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload(app(TenantManager::class)->require()));
    }

    public function update(UpdateTenantCapabilitiesRequest $request): JsonResponse
    {
        abort_unless($request->user()?->can('settings.manage'), Response::HTTP_FORBIDDEN);

        $tenant = app(TenantManager::class)->require();
        $this->capabilities->replaceEnabled($tenant, $request->validated('capabilities'));

        return response()->json($this->payload($tenant));
    }

    private function payload(Tenant $tenant): array
    {
        $enabled = $this->capabilities->enabledKeys($tenant);

        return [
            'data' => [
                'tenant_id' => $tenant->id,
                'enabled' => $enabled,
                'capabilities' => array_map(
                    fn (array $definition): array => $definition + [
                        'required' => in_array($definition['key'], BaseCapabilities::REQUIRED, true),
                        'enabled' => in_array($definition['key'], $enabled, true),
                    ],
                    BaseCapabilities::definitions(),
                ),
            ],
        ];
    }
}
