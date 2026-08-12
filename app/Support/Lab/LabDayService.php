<?php

namespace App\Support\Lab;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;
use Throwable;

/**
 * Ejecuta un "dia simulado" de operacion real contra una API de INVENTARIOARENS.
 *
 * Recorre el ciclo completo de negocio via HTTP autenticado: login -> bootstrap POS ->
 * ventas (cantidad y serializado) -> devolucion -> compra + recepcion -> traslado
 * logistico. Genera un reporte por fase para el laboratorio automatizado.
 *
 * El destino puede ser el backend local (127.0.0.1:8000), una instalacion de prueba
 * del VPS o la nube (con --allow-production y datos desechables loadtest-*).
 */
class LabDayService
{
    public function __construct(private readonly HttpFactory $http) {}

    /**
     * @param  array{base_url:string, tenant:string, email:string, password:string, sales:int, warehouse_origin:string, warehouse_destination:string, supplier_document:string}  $config
     */
    public function runDay(array $config): array
    {
        $report = [
            'started_at' => now()->toIso8601String(),
            'base_url' => $config['base_url'],
            'tenant' => $config['tenant'],
            'phases' => [],
        ];

        $token = $this->login($config['base_url'], $config['tenant'], $config['email'], $config['password']);
        $report['phases']['login'] = ['ok' => true];

        $bootstrap = $this->bootstrap($config['base_url'], $config['tenant'], $token);
        $report['phases']['bootstrap'] = [
            'ok' => true,
            'warehouse_id' => $bootstrap['warehouse_id'],
            'destination_warehouse_id' => $bootstrap['destination_warehouse_id'],
            'session_id' => $bootstrap['session_id'],
            'product_id' => $bootstrap['product_id'],
            'product_sku' => $bootstrap['product_sku'],
            'product_price' => $bootstrap['product_price'],
        ];

        $report['phases']['sales'] = $this->runSales(
            baseUrl: $config['base_url'],
            tenant: $config['tenant'],
            token: $token,
            bootstrap: $bootstrap,
            sales: (int) $config['sales'],
        );

        if (($report['phases']['sales']['paid'] ?? 0) > 0) {
            $report['phases']['sales_return'] = $this->runSalesReturn(
                baseUrl: $config['base_url'],
                tenant: $config['tenant'],
                token: $token,
                saleId: $report['phases']['sales']['first_sale_id'],
            );
        }

        $report['phases']['purchase'] = $this->runPurchase(
            baseUrl: $config['base_url'],
            tenant: $config['tenant'],
            token: $token,
            warehouseId: $bootstrap['warehouse_id'],
            supplierDocument: $config['supplier_document'],
            productId: $bootstrap['product_id'],
            productSku: $bootstrap['product_sku'],
            price: $bootstrap['product_price'],
        );

        $report['phases']['transfer'] = $this->runTransfer(
            baseUrl: $config['base_url'],
            tenant: $config['tenant'],
            token: $token,
            originWarehouseId: $bootstrap['warehouse_id'],
            destinationWarehouseId: $bootstrap['destination_warehouse_id'],
            productId: $bootstrap['product_id'],
            productSku: $bootstrap['product_sku'],
        );

        $report['finished_at'] = now()->toIso8601String();

        return $report;
    }

    /**
     * @return array{token:string}
     */
    private function login(string $baseUrl, string $tenant, string $email, string $password): string
    {
        $response = $this->http->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant' => $tenant,
            'X-Requested-With' => 'XMLHttpRequest',
        ])->timeout(30)->post(rtrim($baseUrl, '/').'/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);

        if ($response->status() !== 201) {
            throw new RuntimeException('Login fallo: HTTP '.$response->status().' '.$response->body());
        }

        $token = data_get($response->json('data'), 'token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Login no devolvio token.');
        }

        return $token;
    }

    /**
     * @return array{warehouse_id:int, destination_warehouse_id:int, session_id:int, price_list_id:int, payment_method_id:int, product_id:int, product_sku:string, product_price:float}
     */
    private function bootstrap(string $baseUrl, string $tenant, string $token): array
    {
        $response = $this->http->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant' => $tenant,
            'Authorization' => 'Bearer '.$token,
        ])->timeout(30)->get(rtrim($baseUrl, '/').'/pos/bootstrap');

        if ($response->status() !== 200) {
            throw new RuntimeException('Bootstrap POS fallo: HTTP '.$response->status().' '.$response->body());
        }

        $data = $response->json();

        $warehouses = $data['warehouses'] ?? [];
        $priceLists = $data['price_lists'] ?? [];
        $paymentMethods = $data['payment_methods'] ?? [];
        $session = $data['open_session'] ?? null;

        $warehouse = $warehouses[0] ?? null;
        if (! $warehouse) {
            throw new RuntimeException('Bootstrap no devolvio almacenes.');
        }

        $sessionId = $session['id'] ?? null;
        if (! $sessionId) {
            throw new RuntimeException('Bootstrap no devolvio sesion de caja abierta.');
        }

        $priceList = collect($priceLists)->first(fn ($list) => ($list['is_default'] ?? false)) ?? $priceLists[0] ?? null;
        $priceListId = $priceList['id'] ?? null;
        if (! $priceListId) {
            throw new RuntimeException('Bootstrap no devolvio lista de precio.');
        }

        $paymentMethod = collect($paymentMethods)->first(fn ($method) => ($method['method'] ?? '') === 'cash') ?? $paymentMethods[0] ?? null;
        $paymentMethodId = $paymentMethod['id'] ?? null;
        if (! $paymentMethodId) {
            throw new RuntimeException('Bootstrap no devolvio metodo de pago.');
        }

        $product = $this->findLabProduct($baseUrl, $tenant, $token);

        return [
            'warehouse_id' => (int) $warehouse['id'],
            'destination_warehouse_id' => (int) (($warehouses[1] ?? $warehouses[0])['id'] ?? $warehouse['id']),
            'session_id' => (int) $sessionId,
            'price_list_id' => (int) $priceListId,
            'payment_method_id' => (int) $paymentMethodId,
            'product_id' => (int) $product['id'],
            'product_sku' => (string) $product['sku'],
            'product_price' => (float) ($product['base_price'] ?? 1),
        ];
    }

    /**
     * @return array{id:int, sku:string, base_price:float}
     */
    private function findLabProduct(string $baseUrl, string $tenant, string $token): array
    {
        $response = $this->http->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant' => $tenant,
            'Authorization' => 'Bearer '.$token,
        ])->timeout(30)->get(rtrim($baseUrl, '/').'/products', [
            'per_page' => 10,
        ]);

        if ($response->status() !== 200) {
            throw new RuntimeException('Listado de productos fallo: HTTP '.$response->status().' '.$response->body());
        }

        $products = $response->json('data') ?? [];
        $product = null;
        foreach ($products as $item) {
            $sku = (string) ($item['sku'] ?? '');
            if (
                ($item['tracking_type'] ?? '') === 'quantity'
                && ($item['is_active'] ?? false) !== false
                && ! str_contains(strtoupper($sku), '-RACE-')
            ) {
                $product = $item;
                break;
            }
        }

        if (! $product) {
            throw new RuntimeException('No se encontro un producto por cantidad para el laboratorio.');
        }

        return [
            'id' => (int) $product['id'],
            'sku' => (string) $product['sku'],
            'base_price' => (float) ($product['base_price'] ?? 1),
        ];
    }

    /**
     * @param  array{warehouse_id:int, session_id:int, price_list_id:int, payment_method_id:int, product_id:int, product_sku:string, product_price:float}  $bootstrap
     * @return array{attempts:int, paid:int, first_sale_id:?int}
     */
    private function runSales(string $baseUrl, string $tenant, string $token, array $bootstrap, int $sales): array
    {
        $attempts = 0;
        $paid = 0;
        $firstSaleId = null;

        for ($index = 0; $index < $sales; $index++) {
            $attempts++;

            $idempotencyKey = sprintf('lab-%s-%s-%04d', $tenant, now()->format('YmdHis'), $index + 1);

            $payload = [
                'customer_name' => 'Cliente Lab Simulado',
                'credit' => false,
                'cash_register_session_id' => $bootstrap['session_id'],
                'items' => [[
                    'warehouse_id' => $bootstrap['warehouse_id'],
                    'product_id' => $bootstrap['product_id'],
                    'price_list_id' => $bootstrap['price_list_id'],
                    'price_source' => 'price_list',
                    'quantity' => 1,
                    'product_variant_id' => null,
                    'product_unit_ids' => [],
                    'discount_type' => null,
                    'discount_value' => 0,
                    'discount_reason' => null,
                ]],
                'payments' => [[
                    'payment_method_id' => $bootstrap['payment_method_id'],
                    'method' => 'cash',
                    'currency' => 'USD',
                    'amount' => $bootstrap['product_price'],
                ]],
            ];

            $response = $this->http->withHeaders([
                'Accept' => 'application/json',
                'X-Tenant' => $tenant,
                'Authorization' => 'Bearer '.$token,
                'Content-Type' => 'application/json',
                'Idempotency-Key' => $idempotencyKey,
            ])->timeout(60)->post(rtrim($baseUrl, '/').'/pos/checkouts', $payload);

            if ($response->status() === 201 && ($response->json('data.status') ?? '') === 'paid') {
                $paid++;
                $firstSaleId ??= $response->json('data.sale_id') ?? $response->json('data.id');
            }
        }

        return [
            'attempts' => $attempts,
            'paid' => $paid,
            'first_sale_id' => $firstSaleId,
        ];
    }

    /**
     * @return array{requested:bool, approved:bool, processed:bool}
     */
    private function runSalesReturn(string $baseUrl, string $tenant, string $token, int $saleId): array
    {
        $sale = $this->get($baseUrl, $tenant, $token, "/sales/{$saleId}");
        $saleItem = $sale['items'][0] ?? null;
        if (! $saleItem) {
            throw new RuntimeException('La venta del lab no tiene items para devolver.');
        }

        $created = $this->post($baseUrl, $tenant, $token, '/sales-returns', [
            'sale_id' => $saleId,
            'reason' => 'Devolucion simulada del lab diario',
            'items' => [[
                'sale_item_id' => (int) $saleItem['id'],
                'quantity' => 1,
                'condition' => 'sellable',
                'product_unit_ids' => [],
            ]],
        ]);

        $returnId = (int) data_get($created, 'id');

        $this->post($baseUrl, $tenant, $token, "/sales-returns/{$returnId}/approve", []);
        $this->post($baseUrl, $tenant, $token, "/sales-returns/{$returnId}/process", [
            'refund_mode' => 'none',
            'process_notes' => 'Procesada por el lab diario',
        ]);

        return ['requested' => true, 'approved' => true, 'processed' => true];
    }

    /**
     * @return array{draft:int, received:int, payable:bool}
     */
    private function runPurchase(string $baseUrl, string $tenant, string $token, int $warehouseId, string $supplierDocument, int $productId, string $productSku, float $price): array
    {
        $supplier = $this->getSupplier($baseUrl, $tenant, $token, $supplierDocument);

        $created = $this->post($baseUrl, $tenant, $token, '/purchases', [
            'supplier_id' => $supplier['id'] ?? null,
            'purchase_currency' => 'USD',
            'exchange_rate_type_id' => null,
            'items' => [[
                'warehouse_id' => $warehouseId,
                'product_id' => $productId,
                'product_variant_id' => null,
                'quantity' => 3,
                'unit_cost' => max(1.0, round($price * 0.6, 2)),
                'serial_units' => [],
            ]],
        ]);

        $purchaseId = (int) data_get($created, 'id');

        $received = $this->patch($baseUrl, $tenant, $token, "/purchases/{$purchaseId}/receive", [
            'received_at' => now()->toDateString(),
        ]);

        $payable = data_get($received, 'account_payable') !== null;

        return [
            'draft' => $purchaseId,
            'received' => (int) data_get($received, 'id', $purchaseId),
            'payable' => $payable,
        ];
    }

    /**
     * @return array{product_id:int, sku:string, base_price:float}
     */
    private function getSupplier(string $baseUrl, string $tenant, string $token, string $supplierDocument): array
    {
        $response = $this->http->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant' => $tenant,
            'Authorization' => 'Bearer '.$token,
        ])->timeout(30)->get(rtrim($baseUrl, '/').'/suppliers', [
            'search' => $supplierDocument,
            'limit' => 5,
        ]);

        $suppliers = $response->status() === 200 ? ($response->json('data') ?? []) : [];

        foreach ($suppliers as $supplier) {
            if (($supplier['document_number'] ?? '') === $supplierDocument) {
                return ['id' => (int) $supplier['id']];
            }
        }

        return ['id' => null];
    }

    /**
     * @return array{created:int, prepared:bool, dispatched:bool, received:bool}
     */
    private function runTransfer(string $baseUrl, string $tenant, string $token, int $originWarehouseId, int $destinationWarehouseId, int $productId, string $productSku): array
    {
        if ($originWarehouseId === $destinationWarehouseId) {
            return ['created' => 0, 'prepared' => false, 'dispatched' => false, 'received' => false];
        }

        $created = $this->post($baseUrl, $tenant, $token, '/inventory-transfers', [
            'from_warehouse_id' => $originWarehouseId,
            'to_warehouse_id' => $destinationWarehouseId,
            'reason' => 'Reposicion simulada del lab diario',
            'reference' => 'LAB-TRF-'.now()->format('YmdHis'),
            'validation_mode' => 'logistics',
            'items' => [[
                'product_id' => $productId,
                'quantity' => 1,
            ]],
        ]);

        $transferId = (int) data_get($created, 'id');
        $itemId = (int) data_get($created, 'items.0.id');
        if (! $transferId || ! $itemId) {
            throw new RuntimeException('No se pudo crear el traslado logistico del lab.');
        }

        $this->post($baseUrl, $tenant, $token, "/inventory-transfers/{$transferId}/prepare", [
            'items' => [[
                'inventory_transfer_item_id' => $itemId,
                'prepared_quantity' => 1,
                'prepared_product_unit_ids' => [],
                'difference_reason' => null,
                'difference_notes' => null,
            ]],
        ]);

        $this->post($baseUrl, $tenant, $token, "/inventory-transfers/{$transferId}/dispatch", [
            'notes' => 'Despachado por el lab diario',
        ]);

        $this->post($baseUrl, $tenant, $token, "/inventory-transfers/{$transferId}/receive", [
            'items' => [[
                'inventory_transfer_item_id' => $itemId,
                'received_quantity' => 1,
                'received_product_unit_ids' => [],
                'difference_reason' => null,
                'difference_notes' => null,
            ]],
        ]);

        return ['created' => $transferId, 'prepared' => true, 'dispatched' => true, 'received' => true];
    }

    private function get(string $baseUrl, string $tenant, string $token, string $path): array
    {
        $response = $this->http->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant' => $tenant,
            'Authorization' => 'Bearer '.$token,
        ])->timeout(30)->get(rtrim($baseUrl, '/').$path);

        if ($response->status() !== 200) {
            throw new RuntimeException("GET {$path} fallo: HTTP ".$response->status().' '.$response->body());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    private function post(string $baseUrl, string $tenant, string $token, string $path, array $payload): array
    {
        $response = $this->http->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant' => $tenant,
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->timeout(60)->post(rtrim($baseUrl, '/').$path, $payload);

        if ($response->status() < 200 || $response->status() >= 300) {
            throw new RuntimeException("POST {$path} fallo: HTTP ".$response->status().' '.$response->body());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    private function patch(string $baseUrl, string $tenant, string $token, string $path, array $payload): array
    {
        $response = $this->http->withHeaders([
            'Accept' => 'application/json',
            'X-Tenant' => $tenant,
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
        ])->timeout(60)->patch(rtrim($baseUrl, '/').$path, $payload);

        if ($response->status() < 200 || $response->status() >= 300) {
            throw new RuntimeException("PATCH {$path} fallo: HTTP ".$response->status().' '.$response->body());
        }

        return $response->json('data') ?? $response->json() ?? [];
    }

    /**
     * @param  array{base_url:string, tenant:string, email:string, password:string}  $config
     */
    public function verifyAccess(array $config): array
    {
        try {
            $token = $this->login($config['base_url'], $config['tenant'], $config['email'], $config['password']);

            return ['ok' => true, 'token_length' => strlen($token)];
        } catch (Throwable $error) {
            return ['ok' => false, 'error' => $error->getMessage()];
        }
    }
}
