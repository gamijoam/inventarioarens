<?php

namespace Tests\Feature\Console;

use App\Support\Lab\LabDayService;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LabDayServiceTest extends TestCase
{
    private const BASE = 'http://127.0.0.1:8000/api';

    public function test_full_business_cycle_executes_expected_http_flow(): void
    {
        Http::fake([
            self::BASE.'/auth/login' => Http::response([
                'data' => ['token' => 'lab-token-123', 'user' => ['id' => 1]],
            ], 201),

            self::BASE.'/pos/bootstrap' => Http::response([
                'warehouses' => [
                    ['id' => 11, 'name' => 'WH1'],
                    ['id' => 12, 'name' => 'WH2'],
                ],
                'open_session' => ['id' => 21],
                'price_lists' => [['id' => 31, 'is_default' => true]],
                'payment_methods' => [['id' => 41, 'method' => 'cash']],
            ], 200),

            self::BASE.'/products*' => Http::response([
                'data' => [
                    [
                        'id' => 51,
                        'sku' => 'LABDAY-01-RACE-QTY',
                        'base_price' => 25,
                        'tracking_type' => 'quantity',
                        'is_active' => true,
                    ],
                    [
                        'id' => 52,
                        'sku' => 'LABDAY-01-0001',
                        'base_price' => 8,
                        'tracking_type' => 'quantity',
                        'is_active' => true,
                    ],
                ],
            ], 200),

            self::BASE.'/pos/checkouts' => Http::response([
                'data' => ['id' => 61, 'status' => 'paid', 'sale_id' => 71],
            ], 201),

            self::BASE.'/sales/71' => Http::response([
                'data' => ['id' => 71, 'items' => [['id' => 81]]],
            ], 200),

            self::BASE.'/sales-returns*' => Http::response([
                'data' => ['id' => 91, 'status' => 'processed'],
            ], 201),

            self::BASE.'/suppliers*' => Http::response([
                'data' => [['id' => 101, 'document_number' => 'LAB-SUP-01']],
            ], 200),

            self::BASE.'/purchases*' => Http::response([
                'data' => ['id' => 111, 'account_payable' => ['id' => 121]],
            ], 201),

            self::BASE.'/inventory-transfers*' => Http::response([
                'data' => ['id' => 131, 'items' => [['id' => 141]]],
            ], 201),
        ]);

        $service = new LabDayService(app(HttpFactory::class));

        $report = $service->runDay([
            'base_url' => self::BASE,
            'tenant' => 'labday-01',
            'email' => 'labday-01@loadtest.local',
            'password' => 'labday-password-2026',
            'sales' => 2,
            'warehouse_origin' => 'LAB-01-01',
            'warehouse_destination' => 'LAB-01-02',
            'supplier_document' => 'LAB-SUP-01',
        ]);

        $this->assertTrue($report['phases']['login']['ok']);
        $this->assertSame(11, $report['phases']['bootstrap']['warehouse_id']);
        $this->assertSame(21, $report['phases']['bootstrap']['session_id']);
        $this->assertSame(52, $report['phases']['bootstrap']['product_id']);
        $this->assertSame(2, $report['phases']['sales']['paid']);
        $this->assertSame(71, $report['phases']['sales']['first_sale_id']);
        $this->assertTrue($report['phases']['sales_return']['processed']);
        $this->assertTrue($report['phases']['purchase']['payable']);
        $this->assertTrue($report['phases']['transfer']['received']);

        Http::assertSentCount(16);
    }

    public function test_login_failure_throws_runtime_exception(): void
    {
        Http::fake([
            self::BASE.'/auth/login' => Http::response(['message' => 'Credenciales invalidas'], 422),
        ]);

        $service = new LabDayService(app(HttpFactory::class));

        $this->expectExceptionMessage('Login fallo');

        $service->runDay([
            'base_url' => self::BASE,
            'tenant' => 'labday-01',
            'email' => 'labday-01@loadtest.local',
            'password' => 'wrong-password',
            'sales' => 1,
            'supplier_document' => 'LAB-SUP-01',
        ]);
    }

    public function test_bootstrap_without_open_session_throws(): void
    {
        Http::fake([
            self::BASE.'/auth/login' => Http::response(['data' => ['token' => 't']], 201),
            self::BASE.'/pos/bootstrap' => Http::response([
                'warehouses' => [['id' => 1]],
                'open_session' => null,
                'price_lists' => [],
                'payment_methods' => [],
            ], 200),
        ]);

        $service = new LabDayService(app(HttpFactory::class));

        $this->expectExceptionMessage('sesion de caja');

        $service->runDay([
            'base_url' => self::BASE,
            'tenant' => 'labday-01',
            'email' => 'labday-01@loadtest.local',
            'password' => 'labday-password-2026',
            'sales' => 1,
            'supplier_document' => 'LAB-SUP-01',
        ]);
    }
}
