<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Requests\CreatePlatformAdminRequest;
use App\Modules\Tenancy\Requests\ResetPlatformAdminPasswordRequest;
use App\Modules\Tenancy\Requests\UpdatePlatformAdminRequest;
use App\Modules\Tenancy\Resources\PlatformAdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PlatformAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        $admins = User::query()
            ->where('is_platform_admin', true)
            ->orderBy('name')
            ->get();

        return PlatformAdminResource::collection($admins);
    }

    public function show(Request $request, User $admin): PlatformAdminResource
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($admin->is_platform_admin, Response::HTTP_NOT_FOUND, 'User is not a platform admin.');

        return PlatformAdminResource::make($admin);
    }

    public function store(CreatePlatformAdminRequest $request): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        $data = $request->validated();
        $email = Str::lower(trim($data['email']));
        $password = ! empty($data['password']) ? $data['password'] : Str::random(32);

        $user = User::query()->where('email', $email)->first();
        $generatedPassword = null;

        if ($user) {
            if ((bool) $user->is_platform_admin) {
                return PlatformAdminResource::make($user)
                    ->response()
                    ->setStatusCode(Response::HTTP_OK);
            }
            $user->is_platform_admin = true;
            $user->save();
        } else {
            $user = User::create([
                'name' => trim($data['name']),
                'email' => $email,
                'password' => Hash::make($password),
                'is_platform_admin' => true,
                'email_verified_at' => now(),
            ]);
            $generatedPassword = $password;
        }

        $this->audit->record('platform_admin.upserted', $user, $request->user(), null, [
            'email' => $user->email,
            'is_platform_admin' => true,
            'created' => $generatedPassword !== null,
        ]);

        return PlatformAdminResource::make($user)
            ->additional(['initial_password' => $generatedPassword])
            ->response()
            ->setStatusCode($generatedPassword ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    public function update(UpdatePlatformAdminRequest $request, User $admin): PlatformAdminResource
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($admin->is_platform_admin, Response::HTTP_NOT_FOUND, 'User is not a platform admin.');

        $oldValues = [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_platform_admin' => (bool) $admin->is_platform_admin,
        ];

        $data = $request->validated();
        $admin->fill(collect($data)->only(['name', 'email', 'is_platform_admin'])->all());
        $admin->save();

        $this->audit->record('platform_admin.updated', $admin, $request->user(), $oldValues, [
            'name' => $admin->name,
            'email' => $admin->email,
            'is_platform_admin' => (bool) $admin->is_platform_admin,
        ]);

        return PlatformAdminResource::make($admin);
    }

    public function resetPassword(ResetPlatformAdminPasswordRequest $request, User $admin): JsonResponse
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($admin->is_platform_admin, Response::HTTP_NOT_FOUND, 'User is not a platform admin.');

        $password = $request->validated('password') ?: Str::random(32);
        $admin->password = Hash::make($password);
        $admin->save();

        $admin->authTokens()->update(['revoked_at' => now()]);

        $this->audit->record('platform_admin.password_reset', $admin, $request->user(), null, [
            'email' => $admin->email,
        ]);

        return response()->json([
            'data' => [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'initial_password' => $request->validated('password') ? null : $password,
                'sessions_revoked' => true,
            ],
        ]);
    }

    public function destroy(Request $request, User $admin): Response
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);
        abort_unless($admin->is_platform_admin, Response::HTTP_NOT_FOUND, 'User is not a platform admin.');

        if ((int) $admin->id === (int) $request->user()->id) {
            throw ValidationException::withMessages([
                'admin' => 'No puedes revocar tu propio acceso de Platform Admin.',
            ]);
        }

        DB::transaction(function () use ($admin): void {
            $admin->authTokens()->update(['revoked_at' => now()]);
            $admin->is_platform_admin = false;
            $admin->save();
        });

        $this->audit->record('platform_admin.revoked', $admin, $request->user(), [
            'email' => $admin->email,
            'is_platform_admin' => true,
        ], [
            'is_platform_admin' => false,
        ]);

        return response()->noContent();
    }
}
