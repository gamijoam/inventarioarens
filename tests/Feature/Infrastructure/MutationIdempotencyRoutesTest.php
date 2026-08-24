<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Routing\Route;
use Tests\TestCase;

class MutationIdempotencyRoutesTest extends TestCase
{
    use WithoutMiddleware;

    public function test_critical_mutation_routes_use_idempotency_middleware(): void
    {
        foreach (self::criticalMutationRoutes() as [$method, $uri]) {
            $route = collect(app('router')->getRoutes())->first(
                fn (Route $candidate): bool => $candidate->uri() === $uri
                    && in_array($method, $candidate->methods(), true),
            );

            $this->assertNotNull($route, "Route {$method} {$uri} was not found.");
            $this->assertContains('idempotency', $route->gatherMiddleware());
        }
    }

    /** @return array<string, array{string, string}> */
    public static function criticalMutationRoutes(): array
    {
        return [
            'sales create' => ['POST', 'api/sales'],
            'sales confirm' => ['PATCH', 'api/sales/{sale}/confirm'],
            'purchase receive' => ['PATCH', 'api/purchases/{purchaseOrder}/receive'],
            'product entry' => ['POST', 'api/product-entries'],
            'product exit' => ['POST', 'api/product-exits'],
            'sales return process' => ['POST', 'api/sales-returns/{salesReturn}/process'],
            'purchase payment' => ['POST', 'api/accounts-payable/{accountsPayable}/payments'],
            'cash movement' => ['POST', 'api/cash-register/sessions/{cashRegisterSession}/movements'],
            'transfer request accept' => ['POST', 'api/inventory-transfer-requests/{inventoryTransferRequest}/accept'],
            'financial adjustment' => ['POST', 'api/financial-adjustments'],
        ];
    }
}
