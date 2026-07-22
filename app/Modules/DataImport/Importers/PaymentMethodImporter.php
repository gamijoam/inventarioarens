<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\PaymentMethods\Models\PaymentMethod;
use Illuminate\Support\Facades\DB;

class PaymentMethodImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'payment_methods';
    }

    public function headers(): array
    {
        return ['code', 'name', 'method', 'currency_mode', 'requires_reference', 'is_active', 'sort_order'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $code = strtoupper($payload['code'] ?? '');
        $name = $payload['name'] ?? null;
        $method = strtolower($payload['method'] ?? '');
        $currencyMode = strtolower($payload['currency_mode'] ?? '');
        $requiresRef = $this->parseBool($payload['requires_reference'] ?? null, false);
        $isActive = $this->parseBool($payload['is_active'] ?? null, true);
        $sortOrder = (int) ($payload['sort_order'] ?? 0);

        $errors = [];
        if (! $code || ! preg_match('/^[A-Z0-9_-]{1,30}$/', $code)) {
            $errors['code'] = 'code es obligatorio (mayusculas, guion, guion bajo)';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        }
        if (! in_array($method, ['cash', 'card', 'mobile_payment', 'transfer', 'zelle', 'external_financing', 'other'], true)) {
            $errors['method'] = 'method invalido';
        }
        if (! in_array($currencyMode, ['usd', 'ves', 'flexible'], true)) {
            $errors['currency_mode'] = 'currency_mode debe ser USD, VES o flexible';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $code);
        }

        return DB::transaction(function () use ($code, $name, $method, $currencyMode, $requiresRef, $isActive, $sortOrder) {
            $existing = PaymentMethod::query()->where('code', $code)->first();
            if ($existing) {
                return ImportRowResult::skipped("Metodo de pago {$code} ya existe", $code);
            }

            $pm = PaymentMethod::create([
                'code' => $code,
                'name' => $name,
                'method' => $method,
                'currency_mode' => strtoupper($currencyMode),
                'requires_reference' => $requiresRef,
                'is_active' => $isActive,
                'sort_order' => $sortOrder,
            ]);

            return ImportRowResult::ok($pm->id, $code);
        });
    }

    protected function parseBool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 't', 'si', 'yes', 'y'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'f', 'no', 'n'], true)) {
            return false;
        }

        return $default;
    }
}
