<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\Branches\Models\Branch;
use App\Modules\DataImport\Support\ImportRowResult;
use Illuminate\Support\Facades\DB;

class BranchImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'branches';
    }

    public function headers(): array
    {
        return ['code', 'name', 'status'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $code = $payload['code'] ?? null;
        $name = $payload['name'] ?? null;
        $status = strtolower($payload['status'] ?? 'active');

        $errors = [];
        if (! $code) {
            $errors['code'] = 'code es obligatorio';
        } elseif (! preg_match('/^[A-Za-z0-9._-]{1,40}$/', $code)) {
            $errors['code'] = 'code solo permite letras, numeros, guion, guion bajo y punto';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'name excede 255 caracteres';
        }
        if (! in_array($status, ['active', 'inactive'], true)) {
            $errors['status'] = 'status debe ser active o inactive';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $code);
        }

        return DB::transaction(function () use ($code, $name, $status) {
            $existing = Branch::query()->where('code', $code)->first();
            if ($existing) {
                return ImportRowResult::skipped("Sucursal {$code} ya existe", $code);
            }

            $branch = Branch::create([
                'code' => $code,
                'name' => $name,
                'status' => $status,
            ]);

            return ImportRowResult::ok($branch->id, $code);
        });
    }
}
