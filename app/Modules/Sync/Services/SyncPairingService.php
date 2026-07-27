<?php

namespace App\Modules\Sync\Services;

use App\Models\User;
use App\Modules\Sync\Models\SyncPairingCode;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SyncPairingService
{
    public function __construct(private readonly SyncTokenService $tokens) {}

    public function create(Tenant $currentTenant, User $actor, array $data): array
    {
        abort_unless($actor->isOwnerOf($currentTenant), 403, 'Solo el Owner puede crear codigos de vinculacion.');

        $target = Tenant::query()->findOrFail((int) $data['target_tenant_id']);
        abort_unless(
            $target->id === $currentTenant->id || $target->parent_id === $currentTenant->id,
            403,
            'La empresa seleccionada no pertenece al grupo actual.',
        );

        $email = Str::lower(trim((string) $data['user_email']));
        $user = User::query()->where('email', $email)->first();
        if (! $user || ! $user->belongsToTenant($target)) {
            throw ValidationException::withMessages([
                'user_email' => 'El usuario no pertenece a la empresa seleccionada.',
            ]);
        }

        $plainCode = 'ARNS-'.Str::upper(Str::random(35));
        $pairing = SyncPairingCode::create([
            'target_tenant_id' => $target->id,
            'created_by_user_id' => $actor->id,
            'target_user_id' => $user->id,
            'code_hash' => hash('sha256', $plainCode),
            'node_name' => $data['node_name'],
            'expires_at' => now()->addMinutes((int) ($data['expires_in_minutes'] ?? 15)),
        ]);

        return [
            'code' => $plainCode,
            'expires_at' => $pairing->expires_at->toISOString(),
            'tenant' => [
                'id' => $target->id,
                'name' => $target->name,
                'slug' => $target->slug,
            ],
            'node_name' => $pairing->node_name,
        ];
    }

    public function redeem(array $data, string $ipAddress, ?string $userAgent): array
    {
        return DB::transaction(function () use ($data, $ipAddress, $userAgent): array {
            $pairing = SyncPairingCode::query()
                ->with(['targetTenant', 'targetUser'])
                ->where('code_hash', hash('sha256', $data['code']))
                ->whereNull('redeemed_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $pairing) {
                throw ValidationException::withMessages([
                    'code' => 'El codigo es invalido, ya fue utilizado o expiro.',
                ]);
            }

            $token = $this->tokens->issue(
                tenant: $pairing->targetTenant,
                user: $pairing->targetUser,
                name: $data['node_name'] ?? $pairing->node_name,
                days: 365,
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $pairing->forceFill([
                'redeemed_at' => now(),
                'redeemed_node_code' => $data['node_code'],
            ])->save();

            return $token;
        });
    }
}
