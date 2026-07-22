<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\Customers\Models\Customer;
use App\Modules\DataImport\Support\ImportRowResult;
use Illuminate\Support\Facades\DB;

class CustomerImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'customers';
    }

    public function headers(): array
    {
        return ['document_type', 'document_number', 'name', 'phone', 'email', 'fiscal_address', 'is_active'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $docType = strtoupper($payload['document_type'] ?? '');
        $docNumber = trim((string) ($payload['document_number'] ?? ''));
        $name = $payload['name'] ?? null;
        $phone = $payload['phone'] ?? null;
        $email = $payload['email'] ?? null;
        $fiscalAddress = $payload['fiscal_address'] ?? null;
        $isActive = $this->parseBool($payload['is_active'] ?? null, true);

        $errors = [];
        if (! in_array($docType, ['V', 'E', 'J', 'G', 'P'], true)) {
            $errors['document_type'] = 'document_type debe ser V, E, J, G o P';
        }
        if ($docNumber === '') {
            $errors['document_number'] = 'document_number es obligatorio';
        } elseif (mb_strlen($docNumber) > 50) {
            $errors['document_number'] = 'document_number excede 50 caracteres';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'name excede 255 caracteres';
        }
        if ($email !== null && $email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'email no es valido';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, "{$docType}-{$docNumber}");
        }

        return DB::transaction(function () use ($docType, $docNumber, $name, $phone, $email, $fiscalAddress, $isActive) {
            $existing = Customer::query()
                ->where('document_type', $docType)
                ->where('document_number', $docNumber)
                ->first();

            if ($existing) {
                return ImportRowResult::skipped("Cliente {$docType}-{$docNumber} ya existe", "{$docType}-{$docNumber}");
            }

            $customer = Customer::create([
                'document_type' => $docType,
                'document_number' => $docNumber,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'fiscal_address' => $fiscalAddress,
                'is_active' => $isActive,
                'is_generic' => false,
            ]);

            return ImportRowResult::ok($customer->id, "{$docType}-{$docNumber}");
        });
    }

    protected function parseBool(?string $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }
        $v = strtolower(trim($value));
        if (in_array($v, ['1', 'true', 't', 'si', 'yes', 'y', 'activo', 'active'], true)) {
            return true;
        }
        if (in_array($v, ['0', 'false', 'f', 'no', 'n', 'inactivo', 'inactive'], true)) {
            return false;
        }

        return $default;
    }
}
