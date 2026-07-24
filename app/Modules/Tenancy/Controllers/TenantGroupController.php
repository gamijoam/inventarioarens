<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\Products\Models\Product;
use App\Modules\Products\Resources\ProductResource;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Requests\StoreSpinoffRequest;
use App\Modules\Tenancy\Requests\StoreTenantGroupRequest;
use App\Modules\Tenancy\Resources\GroupResource;
use App\Modules\Tenancy\Resources\SpinoffResource;
use App\Modules\Tenancy\Resources\TenantResource;
use App\Modules\Tenancy\Services\CrossTenantUserService;
use App\Modules\Tenancy\Services\TenantPromotionService;
use App\Modules\Tenancy\Services\TenantRegistrationService;
use App\Modules\Tenancy\Services\TenantSpinoffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

class TenantGroupController extends Controller
{
    public function __construct(
        private readonly TenantRegistrationService $service,
        private readonly CrossTenantUserService $userService,
        private readonly TenantSpinoffService $spinoffService,
        private readonly TenantPromotionService $promotionService,
    ) {}

    public function store(StoreTenantGroupRequest $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $result = $this->service->registerGroupWithInitialTenant(
            $request->validated(),
            $user,
        );

        return response()->json([
            'data' => [
                'group' => GroupResource::make($result['group']),
                'tenant' => TenantResource::make($result['tenant']),
            ],
        ], Response::HTTP_CREATED);
    }

    /**
     * Lista los grupos donde el user es Owner (no solo member).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $groups = Tenant::query()
            ->groups()
            ->whereHas('users', function ($q) use ($user): void {
                $q->where('users.id', $user->id)->where('tenant_user.status', 'active');
            })
            ->whereIn('id', function ($sub) use ($user): void {
                $sub->select('roles.tenant_id')
                    ->from('roles')
                    ->join('model_has_roles', 'model_has_roles.role_id', '=', 'roles.id')
                    ->where('model_has_roles.model_type', User::class)
                    ->where('model_has_roles.model_id', $user->id)
                    ->where('roles.name', 'Owner')
                    ->whereNotNull('roles.tenant_id');
            })
            ->withCount(['children', 'users'])
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'slug' => $g->slug,
                'domain' => $g->domain,
                'plan' => $g->plan,
                'status' => $g->status,
                'children_count' => (int) $g->children_count,
                'users_count' => (int) $g->users_count,
                'is_owner' => $user->isOwnerOf($g),
            ]);

        return response()->json(['data' => $groups]);
    }

    /**
     * Spinoffs (empresas hijas) de un grupo.
     * Cualquier miembro activo del grupo (no necesariamente Owner).
     */
    public function spinoffs(Request $request, Tenant $group): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');
        abort_unless($user->belongsToTenant($group), 403, 'User is not a member of this group.');

        $spinoffs = Tenant::query()
            ->spinoffs()
            ->where('parent_id', $group->id)
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $t): array => [
                'id' => $t->id,
                'name' => $t->name,
                'slug' => $t->slug,
                'domain' => $t->domain,
                'plan' => $t->plan,
                'status' => $t->status,
                'users_count' => (int) $t->users_count,
            ]);

        return response()->json(['data' => $spinoffs]);
    }

    /**
     * Catalogo compartido del grupo: lista los productos maestros del grupo
     * junto con la copia operativa en cada spinoff (o null si no se ha
     * propagado a esa tienda). Pensado para que el Owner del grupo vea el
     * estado de propagacion de su catalogo.
     */
    public function sharedProducts(Request $request, Tenant $group): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');
        abort_unless($user->isOwnerOf($group), 403, 'Only Owners of the group can view the shared catalog.');

        $masters = Product::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $group->id)
            ->where('is_catalog_master', true)
            ->with(['saleExchangeRateType', 'warrantyPolicy'])
            ->orderBy('name')
            ->get();

        $spinoffs = Tenant::query()
            ->spinoffs()
            ->where('parent_id', $group->id)
            ->orderBy('name')
            ->get();

        $copiesByMaster = [];
        foreach ($spinoffs as $spinoff) {
            $rows = Product::query()
                ->withoutGlobalScopes()
                ->where('catalog_product_id', '!=', null)
                ->whereIn('catalog_product_id', $masters->pluck('id'))
                ->where('tenant_id', $spinoff->id)
                ->get(['id', 'tenant_id', 'catalog_product_id', 'is_active', 'is_catalog_active']);

            foreach ($rows as $row) {
                $copiesByMaster[$row->catalog_product_id][$spinoff->id] = $row;
            }
        }

        $data = $masters->map(function (Product $master) use ($request, $spinoffs, $copiesByMaster): array {
            $copies = [];
            foreach ($spinoffs as $spinoff) {
                $copy = $copiesByMaster[$master->id][$spinoff->id] ?? null;
                $copies[] = [
                    'spinoff_id' => $spinoff->id,
                    'spinoff_slug' => $spinoff->slug,
                    'spinoff_name' => $spinoff->name,
                    'product_id' => $copy?->id,
                    'is_active' => $copy ? (bool) $copy->is_active : null,
                    'is_catalog_active' => $copy ? (bool) $copy->is_catalog_active : null,
                    'propagated' => $copy !== null,
                ];
            }

            return [
                'master' => ProductResource::make($master)->resolve($request),
                'copies' => $copies,
            ];
        });

        return response()->json([
            'data' => [
                'group' => [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                ],
                'spinoffs' => $spinoffs->map(fn (Tenant $t): array => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'slug' => $t->slug,
                ])->values(),
                'products' => $data->values(),
            ],
        ]);
    }

    public function createSpinoff(StoreSpinoffRequest $request, Tenant $group): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');

        $tenant = $this->spinoffService->createSpinoff($group, $request->validated(), $user);
        $tenant->loadCount('users');

        return SpinoffResource::make($tenant)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Promueve una empresa normal a grupo multi-empresa.
     * El actor debe ser miembro activo del tenant actual.
     */
    public function promote(Request $request, Tenant $tenant): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $promoted = $this->promotionService->promote($tenant, $user);
        $promoted->loadCount(['children', 'users']);

        return GroupResource::make($promoted)
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }

    /**
     * Usuarios de toda la organizacion (grupo + spinoffs).
     * Solo Owners del grupo.
     */
    public function users(Request $request, Tenant $group): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');
        abort_unless($user->isOwnerOf($group), 403, 'Only Owners of the group can list organization users.');

        $users = $this->userService->listUsers($group, 'organization');
        $users->through(fn (User $listedUser): array => [
            'id' => $listedUser->id,
            'name' => $listedUser->name,
            'email' => $listedUser->email,
            'status' => $this->organizationUserStatus($listedUser),
            'roles' => $listedUser->roles
                ->map(fn ($role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                ])
                ->values(),
            'tenants' => $listedUser->tenants
                ->map(fn (Tenant $tenant): array => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'is_group' => (bool) $tenant->is_group,
                    'status' => $tenant->pivot?->status ?? 'active',
                ])
                ->values(),
        ]);

        return response()->json(['data' => $users]);
    }

    /**
     * Adjunta un usuario existente (o crea uno nuevo) a un tenant del grupo.
     * Solo Owners del grupo.
     */
    public function attachUser(Request $request, Tenant $group): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        abort_unless($group->isGroup(), 404, 'Tenant is not a group root.');
        abort_unless($user->isOwnerOf($group), 403, 'Only Owners of the group can attach users.');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'tenant_slug' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', 'max:100'],
        ]);

        $targetTenant = Tenant::where('slug', $data['tenant_slug'])
            ->where(function ($q) use ($group): void {
                $q->where('id', $group->id)->orWhere('parent_id', $group->id);
            })
            ->firstOrFail();

        $attached = $this->userService->attachUser(
            $targetTenant,
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'] ?? null,
                'roles' => $data['roles'] ?? ['Administrador'],
                'status' => $data['status'] ?? 'active',
            ],
            $user,
        );

        return response()->json(['data' => $attached->only(['id', 'name', 'email'])], 201);
    }

    private function organizationUserStatus(User $user): string
    {
        $statuses = $user->tenants
            ->map(fn (Tenant $tenant): ?string => $tenant->pivot?->status)
            ->filter()
            ->values();

        if ($statuses->contains('active')) {
            return 'active';
        }

        return $statuses->first() ?? 'active';
    }
}
