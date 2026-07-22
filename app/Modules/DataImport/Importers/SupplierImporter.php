<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Support\Facades\DB;

class SupplierImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'suppliers';
    }

    public function headers(): array
    {
        return ['document_type', 'document_number', 'name', 'phone', 'email', 'fiscal_address', 'notes', 'is_active'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $docType = $payload['document_type'] ? strtoupper($payload['document_type']) : null;
        $docNumber = $payload['document_number'] ? trim((string) $payload['document_number']) : null;
        $name = $payload['name'] ?? null;
        $phone = $payload['phone'] ?? null;
        $email = $payload['email'] ?? null;
        $fiscalAddress = $payload['fiscal_address'] ?? null;
        $notes = $payload['notes'] ?? null;
        $isActive = $this->parseBool($payload['is_active'] ?? null, true);

        $errors = [];
        if ($docType !== null && ! in_array($docType, ['V', 'E', 'J', 'G', 'P'], true)) {
            $errors['document_type'] = 'document_type debe ser V, E, J, G o P';
        }
        if ($docType !== null && ! $docNumber) {
            $errors['document_number'] = 'document_number requerido si defines document_type';
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
            return ImportRowResult::failed($errors, $name);
        }

        return DB::transaction(function () use ($docType, $docNumber, $name, $phone, $email, $fiscalAddress, $notes, $isActive) {
            $existing = null;
            if ($docType && $docNumber) {
                $existing = Supplier::query()
                    ->where('document_type', $docType)
                    ->where('document_number', $docNumber)
                    ->first();
            }

            if ($existing) {
                return ImportRowResult::skipped("Proveedor {$docType}-{$docNumber} ya existe", "{$docType}-{$docNumber}");
            }

            $supplier = Supplier::create([
                'document_type' => $docType,
                'document_number' => $docNumber,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'fiscal_address' => $fiscalAddress,
                'notes' => $notes,
                'is_active' => $isActive,
            ]);

            return ImportRowResult::ok($supplier->id, $docType && $docNumber ? "{$docType}-{$docNumber}" : $name);
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
