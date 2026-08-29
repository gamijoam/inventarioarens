<?php

namespace App\Modules\Printing\Services;

use App\Models\User;
use App\Modules\Printing\Models\PrintConnector;
use App\Modules\Printing\Models\PrintConnectorPairingCode;
use App\Modules\Printing\Models\PrintConnectorToken;
use App\Modules\Printing\Models\PrintJob;
use App\Modules\Tenancy\Models\Tenant;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrintConnectorService
{
    public const PAIRING_TTL_MINUTES = 10;

    public const TOKEN_TTL_DAYS = 365;

    public const CLAIM_TTL_MINUTES = 2;

    public function createPairingCode(User $user): array
    {
        $tenant = app(TenantManager::class)->require();
        $code = Str::upper(Str::random(12));
        $pairing = PrintConnectorPairingCode::create([
            'code_hash' => hash('sha256', $code),
            'created_by' => $user->id,
            'expires_at' => now()->addMinutes(self::PAIRING_TTL_MINUTES),
        ]);

        return [
            'code' => $code,
            'expires_at' => $pairing->expires_at?->toISOString(),
            'tenant_id' => $tenant->id,
        ];
    }

    public function register(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $pairing = PrintConnectorPairingCode::withoutGlobalScopes()
                ->where('code_hash', hash('sha256', Str::upper(trim((string) $data['code']))))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $pairing) {
                throw ValidationException::withMessages([
                    'code' => 'El codigo de vinculacion es invalido, ya fue usado o expiro.',
                ]);
            }

            $tenant = Tenant::query()->findOrFail($pairing->tenant_id);
            $tenancy = app(TenantManager::class);
            $tenancy->set($tenant);

            try {
                $alreadyRegistered = PrintConnector::query()
                    ->where('installation_id', $data['installation_id'])
                    ->exists();

                if ($alreadyRegistered) {
                    throw ValidationException::withMessages([
                        'installation_id' => 'Esta instalacion ya esta vinculada a la empresa.',
                    ]);
                }

                $connector = PrintConnector::create([
                    'uuid' => (string) Str::uuid(),
                    'name' => trim((string) $data['name']),
                    'installation_id' => $data['installation_id'],
                    'version' => $data['version'] ?? null,
                    'status' => PrintConnector::STATUS_ACTIVE,
                    'last_seen_at' => now(),
                    'metadata' => $data['metadata'] ?? null,
                ]);
                $plainToken = Str::random(80);
                $token = PrintConnectorToken::create([
                    'print_connector_id' => $connector->id,
                    'token_hash' => hash('sha256', $plainToken),
                    'expires_at' => now()->addDays(self::TOKEN_TTL_DAYS),
                ]);

                $pairing->update([
                    'print_connector_id' => $connector->id,
                    'used_at' => now(),
                ]);

                return [
                    'connector' => $connector->refresh(),
                    'token' => $plainToken,
                    'token_expires_at' => $token->expires_at?->toISOString(),
                ];
            } finally {
                $tenancy->clear();
            }
        });
    }

    public function connectors(): Collection
    {
        return PrintConnector::query()
            ->withCount('stations')
            ->orderBy('name')
            ->get();
    }

    public function revoke(PrintConnector $connector): PrintConnector
    {
        $connector->update(['status' => PrintConnector::STATUS_REVOKED]);
        $connector->tokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return $connector->refresh();
    }

    public function heartbeat(PrintConnector $connector): PrintConnector
    {
        $connector->update(['last_seen_at' => now()]);

        return $connector->refresh();
    }

    public function availableJobs(PrintConnector $connector, int $limit): Collection
    {
        $now = now();

        return PrintJob::query()
            ->with(['station', 'profile'])
            ->where('print_connector_id', $connector->id)
            ->where(function ($query) use ($now): void {
                $query->whereIn('status', [PrintJob::STATUS_CREATED, PrintJob::STATUS_FAILED])
                    ->orWhere(function ($query) use ($now): void {
                        $query->where('status', PrintJob::STATUS_CLAIMED)
                            ->where('claim_expires_at', '<=', $now);
                    });
            })
            ->oldest('id')
            ->limit($limit)
            ->get();
    }

    public function claim(PrintConnector $connector, string $jobUuid): array
    {
        return DB::transaction(function () use ($connector, $jobUuid): array {
            $job = PrintJob::query()
                ->where('uuid', $jobUuid)
                ->where('print_connector_id', $connector->id)
                ->lockForUpdate()
                ->first();

            abort_unless($job, 404, 'Trabajo de impresion no encontrado.');

            $available = in_array($job->status, [PrintJob::STATUS_CREATED, PrintJob::STATUS_FAILED], true)
                || ($job->status === PrintJob::STATUS_CLAIMED && $job->claim_expires_at?->isPast());

            if (! $available) {
                abort(409, 'El trabajo de impresion ya esta siendo procesado.');
            }

            $claimToken = Str::random(80);
            $job->update([
                'status' => PrintJob::STATUS_CLAIMED,
                'claim_token_hash' => hash('sha256', $claimToken),
                'claim_expires_at' => now()->addMinutes(self::CLAIM_TTL_MINUTES),
                'claimed_at' => now(),
                'attempts' => $job->attempts + 1,
                'last_error' => null,
            ]);

            return [
                'claim_token' => $claimToken,
                'job' => $job->refresh()->load(['station', 'profile']),
            ];
        });
    }

    public function acknowledge(PrintConnector $connector, string $jobUuid, string $claimToken, array $data): PrintJob
    {
        return DB::transaction(function () use ($connector, $jobUuid, $claimToken, $data): PrintJob {
            $job = PrintJob::query()
                ->where('uuid', $jobUuid)
                ->where('print_connector_id', $connector->id)
                ->lockForUpdate()
                ->first();

            abort_unless($job, 404, 'Trabajo de impresion no encontrado.');

            $claimHash = hash('sha256', $claimToken);
            abort_unless(
                $job->claim_token_hash && hash_equals($job->claim_token_hash, $claimHash),
                403,
                'Token de reclamo invalido.',
            );

            $status = $data['status'];
            $isTerminal = in_array($job->status, [PrintJob::STATUS_PRINTED, PrintJob::STATUS_GENERATED, PrintJob::STATUS_FAILED], true);
            if ($isTerminal) {
                abort_unless($job->status === $status, 409, 'El trabajo ya tiene otro estado final.');

                return $job->refresh()->load(['station', 'profile']);
            }

            abort_unless(
                in_array($job->status, [PrintJob::STATUS_CLAIMED, PrintJob::STATUS_SENT], true)
                && ! $job->claim_expires_at?->isPast(),
                409,
                'El reclamo del trabajo expiro.',
            );

            $updates = [
                'status' => $status,
                'last_error' => $status === PrintJob::STATUS_FAILED ? ($data['message'] ?? 'Error de impresion.') : null,
                'sent_at' => $status === PrintJob::STATUS_SENT ? now() : $job->sent_at,
                'printed_at' => $status === PrintJob::STATUS_PRINTED ? now() : $job->printed_at,
                'generated_at' => $status === PrintJob::STATUS_GENERATED ? now() : $job->generated_at,
                'claim_expires_at' => null,
            ];
            $job->update($updates);

            return $job->refresh()->load(['station', 'profile']);
        });
    }
}
