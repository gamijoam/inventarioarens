<?php

namespace App\Modules\DataImport\Controllers;

use App\Modules\DataImport\Models\DataImport;
use App\Modules\DataImport\Services\TemplateBuilder;
use App\Modules\DataImport\Support\ImportStatus;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class DataImportTemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly TemplateBuilder $builder) {}

    public function download(Request $request, string $entity): SymfonyResponse
    {
        $this->authorize('viewAny', DataImport::class);

        if (! ImportStatus::isValidEntity($entity)) {
            return response()->json(['message' => 'Entidad invalida.'], 422);
        }

        $current = $request->boolean('current');
        $simple = $request->boolean('simple');
        $format = strtolower((string) $request->input('format', 'csv'));

        if ($simple && $entity !== 'products') {
            return response()->json(['message' => 'La plantilla sencilla solo esta disponible para productos.'], 422);
        }

        try {
            if ($format === 'xlsx') {
                $content = $simple
                    ? $this->builder->buildSimpleProductsXlsx($current)
                    : ($current
                    ? $this->builder->buildCurrentXlsx($entity)
                    : $this->builder->buildXlsx($entity));
            } else {
                $content = $simple
                    ? $this->builder->buildSimpleProducts($current)
                    : ($current
                    ? $this->builder->buildCurrent($entity)
                    : $this->builder->build($entity));
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $prefix = $simple ? ($current ? 'productos_para_actualizar' : 'plantilla_productos_sencilla') : ($current ? 'datos' : 'plantilla');

        if ($format === 'xlsx') {
            $filename = "{$prefix}_{$entity}.xlsx";

            return response($content, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
                'Cache-Control' => 'no-cache, private',
            ]);
        }

        $filename = "{$prefix}_{$entity}.csv";

        return response($content, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control' => 'no-cache, private',
        ]);
    }
}
