<?php

namespace App\Modules\Printing\Controllers;

use App\Modules\Printing\Models\PrintProfile;
use App\Modules\Printing\Requests\StorePrintProfileRequest;
use App\Modules\Printing\Requests\UpdatePrintProfileRequest;
use App\Modules\Printing\Resources\PrintProfileResource;
use App\Support\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class PrintProfileController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->can('printing.view'), Response::HTTP_FORBIDDEN);

        return PrintProfileResource::collection(
            PrintProfile::query()->orderByDesc('is_default')->orderBy('name')->get()
        );
    }

    public function store(StorePrintProfileRequest $request): JsonResponse
    {
        $profile = PrintProfile::create($this->normalize($request->validated()));
        $this->syncDefault($profile);

        return PrintProfileResource::make($profile->refresh())
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(UpdatePrintProfileRequest $request, PrintProfile $printProfile): PrintProfileResource
    {
        $this->ensureTenantResource($printProfile);

        $printProfile->update($this->normalize($request->validated()));
        $this->syncDefault($printProfile->refresh());

        return PrintProfileResource::make($printProfile->refresh());
    }

    public function destroy(Request $request, PrintProfile $printProfile): Response
    {
        abort_unless($request->user()?->can('printing.manage'), Response::HTTP_FORBIDDEN);
        $this->ensureTenantResource($printProfile);

        $printProfile->update(['is_active' => false, 'is_default' => false]);

        return response()->noContent();
    }

    private function normalize(array $data): array
    {
        if (($data['paper_width_mm'] ?? null) === PrintProfile::WIDTH_58 && ! isset($data['characters_per_line'])) {
            $data['characters_per_line'] = 32;
        }

        if (($data['paper_width_mm'] ?? null) === PrintProfile::WIDTH_80 && ! isset($data['characters_per_line'])) {
            $data['characters_per_line'] = 48;
        }

        return $data;
    }

    private function syncDefault(PrintProfile $profile): void
    {
        if (! $profile->is_default) {
            return;
        }

        PrintProfile::query()
            ->whereKeyNot($profile->id)
            ->update(['is_default' => false]);
    }

    private function ensureTenantResource(PrintProfile $profile): void
    {
        abort_unless($profile->tenant_id === app(TenantManager::class)->require()->id, Response::HTTP_NOT_FOUND);
    }
}
