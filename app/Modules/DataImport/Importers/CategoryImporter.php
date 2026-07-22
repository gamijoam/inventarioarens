<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\Category;
use Illuminate\Support\Facades\DB;

class CategoryImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'categories';
    }

    public function headers(): array
    {
        return ['slug', 'name', 'parent_slug', 'description', 'sort_order', 'is_active'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $slug = $payload['slug'] ?? null;
        $name = $payload['name'] ?? null;
        $parentSlug = $payload['parent_slug'] ?? null;
        $description = $payload['description'] ?? null;
        $sortOrder = (int) ($payload['sort_order'] ?? 0);
        $isActive = $this->parseBool($payload['is_active'] ?? null, true);

        $errors = [];
        if (! $slug) {
            $errors['slug'] = 'slug es obligatorio';
        } elseif (! preg_match('/^[a-z0-9-]{1,100}$/', $slug)) {
            $errors['slug'] = 'slug invalido';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        } elseif (mb_strlen($name) > 255) {
            $errors['name'] = 'name excede 255 caracteres';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $slug);
        }

        return DB::transaction(function () use ($slug, $name, $parentSlug, $description, $sortOrder, $isActive) {
            $existing = Category::query()->where('slug', $slug)->first();
            if ($existing) {
                return ImportRowResult::skipped("Categoria {$slug} ya existe", $slug);
            }

            $parentId = null;
            if ($parentSlug) {
                $parent = Category::query()->where('slug', $parentSlug)->first();
                if (! $parent) {
                    return ImportRowResult::failed(
                        ['parent_slug' => "Categoria padre '{$parentSlug}' no existe. Importala primero."],
                        $slug,
                    );
                }
                $parentId = $parent->id;
            }

            $category = Category::create([
                'slug' => $slug,
                'name' => $name,
                'parent_id' => $parentId,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => $isActive,
            ]);

            return ImportRowResult::ok($category->id, $slug);
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
