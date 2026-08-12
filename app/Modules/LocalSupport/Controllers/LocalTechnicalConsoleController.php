<?php

namespace App\Modules\LocalSupport\Controllers;

use App\Modules\LocalSupport\Services\LocalTechnicalConsoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class LocalTechnicalConsoleController extends Controller
{
    public function __construct(private readonly LocalTechnicalConsoleService $console) {}

    public function status(Request $request): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());

        return response()->json(['data' => $this->console->status()]);
    }

    public function serverMode(Request $request): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        return response()->json(['data' => $this->console->setLocalServerMode((bool) $data['enabled'])]);
    }

    public function connect(Request $request): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());
        $data = $request->validate([
            'code' => ['required', 'string', 'size:40'],
            'node_name' => ['required', 'string', 'max:120'],
            'node_code' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9_-]+$/'],
            'interval' => ['required', 'integer', 'min:5', 'max:300'],
            'local_email' => ['required', 'email', 'max:255'],
            'local_user_name' => ['nullable', 'string', 'max:255'],
            'local_password' => ['required', 'string', 'min:8', 'max:255'],
        ]);

        return response()->json(['data' => $this->console->connect($data)], 201);
    }

    public function sync(Request $request, string $tenant): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());
        $data = $request->validate(['cycles' => ['nullable', 'integer', 'min:1', 'max:5']]);

        return response()->json([
            'data' => $this->console->syncNow(Str::slug($tenant), (int) ($data['cycles'] ?? 1)),
        ]);
    }

    public function worker(Request $request, string $tenant): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());
        $data = $request->validate(['action' => ['required', 'string', 'in:install,start,stop,restart']]);

        return response()->json([
            'data' => $this->console->workerAction(Str::slug($tenant), $data['action']),
        ]);
    }

    public function retry(Request $request, string $tenant): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());

        return response()->json([
            'data' => $this->console->retryFailed(Str::slug($tenant)),
        ]);
    }

    public function printerAction(Request $request): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());
        $data = $request->validate(['action' => ['required', 'string', 'in:install,start,stop,restart']]);

        return response()->json([
            'data' => $this->console->printerAction($data['action']),
        ]);
    }

    public function printerTest(Request $request): JsonResponse
    {
        $this->console->assertAvailable((string) $request->ip());

        return response()->json([
            'data' => $this->console->printerTest(),
        ]);
    }
}
