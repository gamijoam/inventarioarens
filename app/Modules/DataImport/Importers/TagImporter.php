<?php

namespace App\Modules\DataImport\Importers;

use App\Modules\DataImport\Support\ImportRowResult;
use App\Modules\Products\Models\Tag;
use Illuminate\Support\Facades\DB;

class TagImporter extends BaseImporter
{
    public function entity(): string
    {
        return 'tags';
    }

    public function headers(): array
    {
        return ['slug', 'name', 'color'];
    }

    protected function processRow(array $payload, int $rowNumber): ImportRowResult
    {
        $slug = $this->normalizeSlug($payload['slug'] ?? null);
        $name = $payload['name'] ?? null;
        $color = $payload['color'] ?? null;

        $errors = [];
        if (! $slug) {
            $errors['slug'] = 'slug es obligatorio';
        } elseif (! preg_match('/^[a-z0-9-]{1,80}$/', $slug)) {
            $errors['slug'] = 'slug invalido';
        }
        if (! $name) {
            $errors['name'] = 'name es obligatorio';
        }
        if ($color !== null && ! preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $errors['color'] = 'color debe tener formato #RRGGBB';
        }
        if ($errors !== []) {
            return ImportRowResult::failed($errors, $slug);
        }

        return DB::transaction(function () use ($slug, $name, $color) {
            $existing = Tag::query()->where('slug', $slug)->first();
            if ($existing) {
                return ImportRowResult::skipped("Tag {$slug} ya existe", $slug);
            }

            $tag = Tag::create([
                'slug' => $slug,
                'name' => $name,
                'color' => $color,
            ]);

            return ImportRowResult::ok($tag->id, $slug);
        });
    }
}
