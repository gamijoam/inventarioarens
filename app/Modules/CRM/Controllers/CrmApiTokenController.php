<?php

namespace App\Modules\CRM\Controllers;

use App\Modules\CRM\Models\CrmApiToken;
use App\Modules\CRM\Requests\StoreCrmApiTokenRequest;
use App\Modules\CRM\Resources\CrmApiTokenResource;
use App\Modules\CRM\Services\CrmApiTokenService;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class CrmApiTokenController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorizeManagement($request);

        return CrmApiTokenResource::collection(
            CrmApiToken::query()->latest('id')->paginate(50)
        );
    }

    public function store(StoreCrmApiTokenRequest $request, CrmApiTokenService $service): JsonResponse
    {
        $result = $service->issue(
            $request->validated(),
            app(TenantManager::class)->require(),
            $request->user(),
            $request,
        );

        $data = CrmApiTokenResource::make($result['token'])->resolve($request);
        $data['token'] = $result['plain_token'];

        return response()->json(['data' => $data], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, int $tokenId, CrmApiTokenService $service): JsonResponse
    {
        $this->authorizeManagement($request);
        $token = $this->tokenForCurrentTenant($tokenId);
        $service->revoke($token, $request->user(), $request);

        return response()->json(['data' => ['revoked' => true, 'token_id' => $token->id]]);
    }

    public function rotate(Request $request, int $tokenId, CrmApiTokenService $service): JsonResponse
    {
        $this->authorizeManagement($request);
        $result = $service->rotate($this->tokenForCurrentTenant($tokenId), $request->user(), $request);

        $data = CrmApiTokenResource::make($result['token'])->resolve($request);
        $data['token'] = $result['plain_token'];

        return response()->json(['data' => $data], Response::HTTP_CREATED);
    }

    private function tokenForCurrentTenant(int $tokenId): CrmApiToken
    {
        return CrmApiToken::query()->whereKey($tokenId)->firstOrFail();
    }

    private function authorizeManagement(Request $request): void
    {
        abort_unless($request->user()?->can('settings.manage'), Response::HTTP_FORBIDDEN);
    }
}
