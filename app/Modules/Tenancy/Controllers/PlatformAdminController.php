<?php

namespace App\Modules\Tenancy\Controllers;

use App\Models\User;
use App\Modules\Tenancy\Requests\CreatePlatformAdminRequest;
use App\Modules\Tenancy\Resources\PlatformAdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PlatformAdminController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()?->isPlatformAdmin(), Response::HTTP_FORBIDDEN);

        $admins = User::query()
            ->where('is_platform_admin', true)
            ->orderBy('name')
            ->get();

        return PlatformAdminResource::collection($admins);
    }

    public function store(CreatePlatformAdminRequest $request): JsonResponse
    {
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

        return PlatformAdminResource::make($user)
            ->additional(['initial_password' => $generatedPassword])
            ->response()
            ->setStatusCode($generatedPassword ? Response::HTTP_CREATED : Response::HTTP_OK);
    }
}
