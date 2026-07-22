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

        $csv = $this->builder->build($entity);
        $filename = "plantilla_{$entity}.csv";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
