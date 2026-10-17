<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\SystemHealthTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Balanza Pro Diagnostics Server')]
#[Version('0.1.0')]
#[Instructions('Herramientas locales y de solo lectura para diagnosticar Balanza Pro. No ejecutan acciones administrativas ni exponen secretos.')]
class BalanzaProDiagnosticsServer extends Server
{
    protected array $tools = [
        SystemHealthTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
