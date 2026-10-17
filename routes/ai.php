<?php

use App\Mcp\Servers\BalanzaProDiagnosticsServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

Mcp::local('balanzapro-diagnostics', BalanzaProDiagnosticsServer::class);

Route::get('/.well-known/oauth-authorization-server/{path?}', static fn () => abort(404))
    ->where('path', '.*');

Route::get('/.well-known/oauth-protected-resource/{path?}', static fn () => abort(404))
    ->where('path', '.*');

Mcp::web('/mcp', BalanzaProDiagnosticsServer::class)
    ->middleware(['throttle:mcp']);
