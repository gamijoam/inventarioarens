<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Devuelve el estado seguro de la aplicacion y su conexion a base de datos, sin datos de empresas ni secretos.')]
#[IsIdempotent]
#[IsReadOnly]
class SystemHealthTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request)
    {
        $connection = DB::connection();
        $connection->select('select 1');

        return Response::structured([
            'application' => [
                'name' => config('app.name'),
                'laravel_version' => app()->version(),
                'environment' => app()->environment(),
            ],
            'database' => [
                'connection' => config('database.default'),
                'driver' => $connection->getDriverName(),
                'status' => 'ok',
            ],
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            //
        ];
    }
}
