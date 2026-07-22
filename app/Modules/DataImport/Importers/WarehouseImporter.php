<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\Branches\Models\Branch;
use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Warehouses\Models\Warehouse;
use Illuminate\Support\Facades\DB;

class WarehouseImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'warehouses';
    }

    public function headers(): array
    {
        return ['code', 'name', 'branch_code', 'status'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $code = $payload['code'] ?? null;
        $name = $payload['name'] ?? null;
        $branchCode = $payload['branch_code'] ?? null;
        $status = strtolower($payload['status'] ?? 'active');

        $errors = [];
        if (! $code) {
            $errors['code'] = 'code es obligatorio';
        } elseif (! preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code)) {
            $errors['code'] = 'code invalido';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        }
        if (! $branchCode) {
            $errors['branch_code'] = 'branch_code es obligatorio';
        }
        if (! in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'status debe ser active o inactive';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $code);
        }

        $branch = Branch::query()->where('code', $branchCode)->first();
        if (! $branch) {
            return ImportRowResult::failed(
                ['branch_code' => "Sucursal '{$branchCode}' no existe en este tenant"],
                $code,
            );
        }

        return DB::transaction(function () use ($code, $name, $branch, $status) {
            $existing = Warehouse::query()->where('code', $code)->first();
            if ($existing) {
                return ImportRowResult::skipped("Almacen {$code} ya existe", $code);
            }

            $warehouse = Warehouse::create([
                'code' => $code,
                'name' => $name,
                'branch_id' => $branch->id,
                'status' => $status,
            ]);

            return ImportRowResult::ok($warehouse->id, $code);
        });
    }
}
