<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\Brand;
use Illuminate\Support\Facades\DB;

class BrandImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'brands';
    }

    public function headers(): array
    {
        return ['slug', 'name', 'description', 'is_active'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $slug = $this->normalizeSlug($payload['slug'] ?? null);
        $name = $payload['name'] ?? null;
        $description = $payload['description'] ?? null;
        $isActiveRaw = $payload['is_active'] ?? null;
        $isActive = $this->parseBool($isActiveRaw, true);

        $errors = [];
        if (! $slug) {
            $errors['slug'] = 'slug es obligatorio';
        } elseif (! preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
            $errors['slug'] = 'slug solo permite minusculas, numeros y guiones';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        } elseif (mb_strlen($name) < 2) {
            $errors['name'] = 'name debe tener al menos 2 caracteres';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'name excede 150 caracteres';
        }
        if ($description !== null && mb_strlen($description) > 500) {
            $errors['description'] = 'description excede 500 caracteres';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $slug);
        }

        return DB::transaction(function () use ($slug, $name, $description, $isActive) {
            $existing = Brand::query()->where('slug', $slug)->first();
            if ($existing) {
                return ImportRowResult::skipped("Marca {$slug} ya existe", $slug);
            }

            $brand = Brand::create([
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'is_active' => $isActive,
            ]);

            return ImportRowResult::ok($brand->id, $slug);
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
