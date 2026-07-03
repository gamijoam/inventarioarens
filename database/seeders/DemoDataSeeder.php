<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\AccountsPayable\Models\AccountsPayable;
use App\Modules\AccountsPayable\Models\AccountsPayablePayment;
use App\Modules\AccountsPayable\Services\AccountsPayableService;
use App\Modules\AccountsReceivable\Models\AccountsReceivable;
use App\Modules\AccountsReceivable\Models\AccountsReceivablePayment;
use App\Modules\AccountsReceivable\Services\AccountsReceivableService;
use App\Modules\Branches\Models\Branch;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Currency\Models\ExchangeRate;
use App\Modules\Currency\Models\ExchangeRateType;
use App\Modules\Customers\Models\Customer;
use App\Modules\FinancialAdjustments\Models\FinancialAdjustment;
use App\Modules\FinancialAdjustments\Services\FinancialAdjustmentService;
use App\Modules\Inventory\Models\ProductUnit;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryMovementService;
use App\Modules\POS\Models\PosOrder;
use App\Modules\POS\Models\PosPayment;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\PaymentReceipts\Services\PaymentReceiptService;
use App\Modules\ProductEntries\Models\ProductEntry;
use App\Modules\ProductEntries\Services\ProductEntryService;
use App\Modules\ProductExits\Models\ProductExit;
use App\Modules\ProductExits\Services\ProductExitService;
use App\Modules\Products\Models\Product;
use App\Modules\PurchaseReturns\Models\PurchaseReturn;
use App\Modules\PurchaseReturns\Services\PurchaseReturnService;
use App\Modules\Purchases\Models\PurchaseOrder;
use App\Modules\Purchases\Services\PurchaseOrderService;
use App\Modules\Suppliers\Models\Supplier;
use App\Modules\SalesReturns\Models\SalesReturn;
use App\Modules\SalesReturns\Services\SalesReturnService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Warehouses\Models\Warehouse;
use App\Support\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        DB::transaction(function (): void {
            $tenantA = $this->tenant('Arens Demo Caracas', 'arens-demo-caracas');
            $tenantB = $this->tenant('Arens Demo Valencia', 'arens-demo-valencia');

            $this->seedCompany($tenantA, [
                'branch_name' => 'Principal Caracas',
                'branch_code' => 'CCS',
                'warehouse_name' => 'Almacen Principal Caracas',
                'warehouse_code' => 'CCS-01',
                'cashier_email' => 'cajero.caracas@demo.test',
                'cashier_name' => 'Cajero Caracas',
                'manager_email' => 'gerente.caracas@demo.test',
                'manager_name' => 'Gerente Caracas',
                'imei_prefix' => '860001',
            ]);

            $this->seedCompany($tenantB, [
                'branch_name' => 'Principal Valencia',
                'branch_code' => 'VAL',
                'warehouse_name' => 'Almacen Principal Valencia',
                'warehouse_code' => 'VAL-01',
                'cashier_email' => 'cajero.valencia@demo.test',
                'cashier_name' => 'Cajero Valencia',
                'manager_email' => 'gerente.valencia@demo.test',
                'manager_name' => 'Gerente Valencia',
                'imei_prefix' => '860002',
            ]);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function seedCompany(Tenant $tenant, array $data): void
    {
        $this->useTenant($tenant);

        $cashier = $this->user($data['cashier_name'], $data['cashier_email']);
        $manager = $this->user($data['manager_name'], $data['manager_email']);
        $this->attachUser($tenant, $cashier);
        $this->attachUser($tenant, $manager);
        $this->assignRole($tenant, $cashier, 'Vendedor');
        $this->assignRole($tenant, $manager, 'Gerente');

        $branch = Branch::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => $data['branch_code']],
            ['name' => $data['branch_name'], 'status' => Branch::STATUS_ACTIVE]
        );

        $warehouse = Warehouse::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => $data['warehouse_code']],
            ['branch_id' => $branch->id, 'name' => $data['warehouse_name'], 'status' => 'active']
        );

        $bcv = $this->rateType('BCV', 'Banco Central de Venezuela', true);
        $parallel = $this->rateType('PARALELO', 'Tasa paralela demo', false);
        $this->rate($bcv, 500, 'Demo BCV');
        $this->rate($parallel, 600, 'Demo paralelo');

        $phone = Product::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => "SAM-A06-{$data['branch_code']}"],
            [
                'name' => 'Samsung A06 128GB',
                'tracking_type' => Product::TRACKING_SERIALIZED,
                'base_price' => 100,
                'sale_currency' => Product::CURRENCY_VES,
                'sale_exchange_rate_type_id' => $parallel->id,
                'is_active' => true,
            ]
        );

        $headphones = Product::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'sku' => "AUD-BT-{$data['branch_code']}"],
            [
                'name' => 'Audifonos Bluetooth',
                'tracking_type' => Product::TRACKING_QUANTITY,
                'base_price' => 25,
                'sale_currency' => Product::CURRENCY_USD,
                'is_active' => true,
            ]
        );

        $this->initialStock($warehouse, $phone, 8, $manager, "Carga demo inicial {$phone->sku}");
        $this->initialStock($warehouse, $headphones, 20, $manager, "Carga demo inicial {$headphones->sku}");
        $this->imeis($tenant, $warehouse, $phone, $data['imei_prefix']);
        $this->productEntryWithImeis($tenant, $warehouse, $phone, $manager, $data['imei_prefix'], $data['branch_code']);
        $this->productExitWithImei($tenant, $warehouse, $phone, $manager, $data['imei_prefix'], $data['branch_code']);
        $supplier = $this->supplier("Proveedor Demo {$data['branch_code']}", "{$tenant->id}900");
        $this->receivedPurchase($tenant, $manager, $supplier, $warehouse, $headphones, "COMPRA-DEMO-{$data['branch_code']}");
        $this->purchaseReturn($tenant, $manager, "COMPRA-DEMO-{$data['branch_code']}");
        $this->accountsPayablePayment($tenant, $manager, "COMPRA-DEMO-{$data['branch_code']}");

        $this->customer('Consumidor final', 'V', "000000{$tenant->id}", true);
        $paidCustomer = $this->customer('Cliente Demo POS Pagado', 'V', "{$tenant->id}001", false);
        $financingCustomer = $this->customer('Cliente Demo Financiamiento', 'V', "{$tenant->id}002", false);

        $cashRegister = $this->cashRegister($tenant, $branch, $cashier);
        $this->paidPosOrder($tenant, $cashRegister, $cashier, $warehouse, $phone, $parallel, $paidCustomer);
        $this->pendingFinancingOrder($tenant, $cashRegister, $cashier, $warehouse, $headphones, $financingCustomer);
        $this->salesReturn($tenant, $manager, $phone);
        $this->creditSale($tenant, $manager, $warehouse, $headphones, $paidCustomer, "VENTA-CREDITO-DEMO-{$data['branch_code']}");
        $this->accountsReceivablePayment($tenant, $manager, "VENTA-CREDITO-DEMO-{$data['branch_code']}");
        $this->financialAdjustments($tenant, $manager, "COMPRA-DEMO-{$data['branch_code']}", "VENTA-CREDITO-DEMO-{$data['branch_code']}");
        $this->paymentReceipts($tenant, $manager);
    }

    private function paidPosOrder(
        Tenant $tenant,
        CashRegisterSession $cashRegister,
        User $cashier,
        Warehouse $warehouse,
        Product $product,
        ExchangeRateType $rateType,
        Customer $customer,
    ): void {
        $this->useTenant($tenant);

        $existing = PosOrder::query()->where('customer_name', 'Cliente Demo POS Pagado')->first();

        if ($existing) {
            $existing->update(['customer_id' => $customer->id]);
            $existing->sale?->update(['customer_id' => $customer->id]);
            return;
        }

        app(PosCheckoutService::class)->checkout(
            cashier: $cashier,
            cashRegisterSession: $cashRegister,
            items: [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 1,
            ]],
            payments: [[
                'method' => PosPayment::METHOD_MOBILE_PAYMENT,
                'currency' => Product::CURRENCY_VES,
                'amount' => 60000,
                'exchange_rate_type_id' => $rateType->id,
                'reference' => 'PM-DEMO-001',
            ]],
            customerId: $customer->id,
            customerName: 'Cliente Demo POS Pagado',
        );
    }

    private function pendingFinancingOrder(
        Tenant $tenant,
        CashRegisterSession $cashRegister,
        User $cashier,
        Warehouse $warehouse,
        Product $product,
        Customer $customer,
    ): void
    {
        $this->useTenant($tenant);

        $existing = PosOrder::query()->where('customer_name', 'Cliente Demo Financiamiento')->first();

        if ($existing) {
            $existing->update(['customer_id' => $customer->id]);
            $existing->sale?->update(['customer_id' => $customer->id]);
            return;
        }

        app(PosCheckoutService::class)->checkout(
            cashier: $cashier,
            cashRegisterSession: $cashRegister,
            items: [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 2,
            ]],
            payments: [[
                'method' => PosPayment::METHOD_EXTERNAL_FINANCING,
                'currency' => Product::CURRENCY_USD,
                'amount' => 50,
                'status' => PosPayment::STATUS_PENDING,
                'external_provider' => 'Financiadora Demo',
                'reference' => 'FIN-DEMO-001',
            ]],
            customerId: $customer->id,
            customerName: 'Cliente Demo Financiamiento',
        );
    }

    private function customer(string $name, string $documentType, string $documentNumber, bool $generic): Customer
    {
        $customer = Customer::query()->firstOrCreate(
            [
                'document_type' => $documentType,
                'document_number' => $documentNumber,
            ],
            [
                'name' => $name,
                'is_generic' => $generic,
                'is_active' => true,
            ]
        );

        $customer->update([
            'name' => $name,
            'is_generic' => $generic,
            'is_active' => true,
        ]);

        return $customer;
    }

    private function tenant(string $name, string $slug): Tenant
    {
        return Tenant::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'status' => 'active', 'plan' => 'demo']
        );
    }

    private function supplier(string $name, string $documentNumber): Supplier
    {
        $supplier = Supplier::query()->firstOrCreate(
            [
                'document_type' => Supplier::DOCUMENT_J,
                'document_number' => $documentNumber,
            ],
            [
                'name' => $name,
                'is_active' => true,
            ]
        );

        $supplier->update([
            'name' => $name,
            'is_active' => true,
        ]);

        return $supplier;
    }

    private function receivedPurchase(
        Tenant $tenant,
        User $user,
        Supplier $supplier,
        Warehouse $warehouse,
        Product $product,
        string $documentNumber,
    ): void {
        $this->useTenant($tenant);

        $existing = PurchaseOrder::query()->where('document_number', $documentNumber)->first();

        if ($existing) {
            if ($existing->status === PurchaseOrder::STATUS_RECEIVED) {
                app(AccountsPayableService::class)->createForPurchase($existing);
            }

            return;
        }

        $purchase = app(PurchaseOrderService::class)->createDraft($user, [
            'supplier_id' => $supplier->id,
            'document_number' => $documentNumber,
            'purchase_currency' => PurchaseOrder::CURRENCY_USD,
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_cost' => 18,
            ]],
        ]);

        app(PurchaseOrderService::class)->receive($purchase, $user);
    }

    private function purchaseReturn(Tenant $tenant, User $user, string $documentNumber): void
    {
        $this->useTenant($tenant);

        $purchase = PurchaseOrder::query()
            ->with('items')
            ->where('document_number', $documentNumber)
            ->first();

        if (! $purchase) {
            return;
        }

        $existingReturn = PurchaseReturn::query()->where('purchase_order_id', $purchase->id)->first();

        if ($existingReturn) {
            app(AccountsPayableService::class)->applyPurchaseReturn($existingReturn);

            return;
        }

        app(PurchaseReturnService::class)->create($user, [
            'purchase_order_id' => $purchase->id,
            'reason' => 'Devolucion demo a proveedor.',
            'items' => [[
                'purchase_item_id' => $purchase->items->first()->id,
                'quantity' => 1,
            ]],
        ]);
    }

    private function accountsPayablePayment(Tenant $tenant, User $user, string $documentNumber): void
    {
        $this->useTenant($tenant);

        $account = AccountsPayable::query()
            ->where('document_number', $documentNumber)
            ->first();

        if (! $account || $account->payments()->exists()) {
            return;
        }

        app(AccountsPayableService::class)->registerPayment($account, $user, [
            'payment_currency' => PurchaseOrder::CURRENCY_USD,
            'amount' => 10,
            'method' => 'transferencia demo',
            'reference' => "PAGO-{$documentNumber}",
            'notes' => 'Abono demo a proveedor.',
        ]);
    }

    private function salesReturn(Tenant $tenant, User $user, Product $product): void
    {
        $this->useTenant($tenant);

        $posOrder = PosOrder::query()
            ->with('sale.items')
            ->where('customer_name', 'Cliente Demo POS Pagado')
            ->first();

        if (! $posOrder?->sale || SalesReturn::query()->where('sale_id', $posOrder->sale->id)->exists()) {
            return;
        }

        $saleItem = $posOrder->sale->items->first();
        $productUnit = ProductUnit::query()
            ->where('product_id', $product->id)
            ->oldest()
            ->first();

        app(SalesReturnService::class)->create($user, [
            'sale_id' => $posOrder->sale->id,
            'reason' => 'Devolucion demo de venta POS pagada.',
            'items' => [[
                'sale_item_id' => $saleItem->id,
                'quantity' => 1,
                'product_unit_ids' => $productUnit ? [$productUnit->id] : [],
            ]],
        ]);
    }

    private function creditSale(
        Tenant $tenant,
        User $user,
        Warehouse $warehouse,
        Product $product,
        Customer $customer,
        string $documentNumber,
    ): void {
        $this->useTenant($tenant);

        if (AccountsReceivable::query()->where('document_number', $documentNumber)->exists()) {
            return;
        }

        $sale = app(\App\Modules\Sales\Services\SaleService::class)->createDraft($user, [[
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'quantity' => 1,
        ]], $customer->id);

        $sale = app(\App\Modules\Sales\Services\SaleService::class)->confirm($sale, $user);

        AccountsReceivable::query()
            ->where('sale_id', $sale->id)
            ->update(['document_number' => $documentNumber]);
    }

    private function accountsReceivablePayment(Tenant $tenant, User $user, string $documentNumber): void
    {
        $this->useTenant($tenant);

        $account = AccountsReceivable::query()
            ->where('document_number', $documentNumber)
            ->first();

        if (! $account || $account->payments()->exists()) {
            return;
        }

        app(AccountsReceivableService::class)->registerPayment($account, $user, [
            'payment_currency' => Product::CURRENCY_USD,
            'amount' => 10,
            'method' => 'cobro demo',
            'reference' => "COBRO-{$documentNumber}",
            'notes' => 'Abono demo de cliente.',
        ]);
    }

    private function paymentReceipts(Tenant $tenant, User $user): void
    {
        $this->useTenant($tenant);

        $receipts = app(PaymentReceiptService::class);

        AccountsReceivablePayment::query()
            ->oldest()
            ->each(fn (AccountsReceivablePayment $payment) => $receipts->issueForReceivablePayment($payment, $user));

        AccountsPayablePayment::query()
            ->oldest()
            ->each(fn (AccountsPayablePayment $payment) => $receipts->issueForPayablePayment($payment, $user));
    }

    private function financialAdjustments(Tenant $tenant, User $user, string $purchaseDocument, string $saleDocument): void
    {
        $this->useTenant($tenant);

        $service = app(FinancialAdjustmentService::class);

        $payable = AccountsPayable::query()->where('document_number', $purchaseDocument)->first();

        if ($payable && ! FinancialAdjustment::query()->where('reason', "Ajuste demo proveedor {$purchaseDocument}")->exists()) {
            $service->create($user, [
                'account_type' => FinancialAdjustment::ACCOUNT_PAYABLE,
                'account_id' => $payable->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 1,
                'reason' => "Ajuste demo proveedor {$purchaseDocument}",
                'notes' => 'Nota financiera demo sin movimiento de inventario.',
            ]);
        }

        $receivable = AccountsReceivable::query()->where('document_number', $saleDocument)->first();

        if ($receivable && ! FinancialAdjustment::query()->where('reason', "Ajuste demo cliente {$saleDocument}")->exists()) {
            $service->create($user, [
                'account_type' => FinancialAdjustment::ACCOUNT_RECEIVABLE,
                'account_id' => $receivable->id,
                'currency' => Product::CURRENCY_USD,
                'amount' => 1,
                'reason' => "Ajuste demo cliente {$saleDocument}",
                'notes' => 'Descuento financiero demo sin devolucion fisica.',
            ]);
        }
    }

    private function user(string $name, string $email): User
    {
        return User::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => 'password']
        );
    }

    private function attachUser(Tenant $tenant, User $user): void
    {
        if (! $user->tenants()->whereKey($tenant->id)->exists()) {
            $user->tenants()->attach($tenant, ['status' => 'active']);
        }
    }

    private function assignRole(Tenant $tenant, User $user, string $roleName): void
    {
        setPermissionsTeamId($tenant->id);

        $role = Role::findOrCreate($roleName, 'web');
        $user->assignRole($role);
    }

    private function rateType(string $code, string $name, bool $default): ExchangeRateType
    {
        $rateType = ExchangeRateType::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'is_default' => $default, 'is_active' => true]
        );

        $rateType->update(['name' => $name, 'is_default' => $default, 'is_active' => true]);

        return $rateType;
    }

    private function rate(ExchangeRateType $rateType, float $rate, string $source): void
    {
        ExchangeRate::query()->firstOrCreate(
            [
                'exchange_rate_type_id' => $rateType->id,
                'effective_at' => now()->startOfDay(),
            ],
            [
                'base_currency' => ExchangeRate::BASE_USD,
                'quote_currency' => ExchangeRate::QUOTE_VES,
                'rate' => $rate,
                'is_active' => true,
                'source' => $source,
            ]
        );
    }

    private function initialStock(Warehouse $warehouse, Product $product, float $quantity, User $user, string $reason): void
    {
        if (StockMovement::query()->where('reason', $reason)->exists()) {
            return;
        }

        app(InventoryMovementService::class)->purchase(
            warehouse: $warehouse,
            product: $product,
            quantity: $quantity,
            unitCost: (float) $product->base_price,
            createdBy: $user,
            reason: $reason,
        );
    }

    private function imeis(Tenant $tenant, Warehouse $warehouse, Product $product, string $prefix): void
    {
        $movement = StockMovement::query()
            ->where('product_id', $product->id)
            ->where('type', 'purchase')
            ->oldest()
            ->first();

        foreach (range(1, 8) as $index) {
            ProductUnit::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                    'serial_number' => "{$prefix}000000{$index}",
                ],
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'status' => ProductUnit::STATUS_AVAILABLE,
                    'acquired_stock_movement_id' => $movement?->id,
                ]
            );
        }
    }

    private function productEntryWithImeis(
        Tenant $tenant,
        Warehouse $warehouse,
        Product $product,
        User $user,
        string $prefix,
        string $branchCode,
    ): void {
        $this->useTenant($tenant);

        $reference = "ENT-DEMO-30-IMEIS-{$branchCode}";

        if (ProductEntry::query()->where('reference', $reference)->exists()) {
            return;
        }

        $serialUnits = [];

        foreach (range(101, 130) as $index) {
            $serialUnits[] = [
                'serial_type' => ProductUnit::SERIAL_TYPE_IMEI,
                'serial_number' => "{$prefix}0000{$index}",
            ];
        }

        app(ProductEntryService::class)->create($user, [
            'reason' => 'Entrada demo de 30 IMEIs Samsung A06',
            'reference' => $reference,
            'notes' => 'Entrada operativa demo para revisar carga masiva de IMEIs.',
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 30,
                'unit_cost' => 80,
                'serial_units' => $serialUnits,
            ]],
        ]);
    }

    private function productExitWithImei(
        Tenant $tenant,
        Warehouse $warehouse,
        Product $product,
        User $user,
        string $prefix,
        string $branchCode,
    ): void {
        $this->useTenant($tenant);

        $reference = "SAL-DEMO-GARANTIA-{$branchCode}";

        if (ProductExit::query()->where('reference', $reference)->exists()) {
            return;
        }

        $unit = ProductUnit::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->where('serial_type', ProductUnit::SERIAL_TYPE_IMEI)
            ->where('serial_number', "{$prefix}0000130")
            ->where('status', ProductUnit::STATUS_AVAILABLE)
            ->first();

        if (! $unit) {
            return;
        }

        app(ProductExitService::class)->create($user, [
            'reason' => ProductExit::REASON_WARRANTY,
            'reference' => $reference,
            'notes' => 'Salida demo de un IMEI por garantia.',
            'items' => [[
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'product_unit_ids' => [$unit->id],
            ]],
        ]);
    }

    private function cashRegister(Tenant $tenant, Branch $branch, User $cashier): CashRegisterSession
    {
        $this->useTenant($tenant);

        $existing = CashRegisterSession::query()
            ->where('cashier_id', $cashier->id)
            ->where('status', CashRegisterSession::STATUS_OPEN)
            ->first();

        if ($existing) {
            return $existing;
        }

        return app(CashRegisterService::class)->open(
            operator: $cashier,
            branch: $branch,
            cashier: $cashier,
            data: [
                'opening_currency' => Product::CURRENCY_USD,
                'opening_amount' => 100,
                'notes' => 'Caja demo abierta para pruebas manuales.',
            ],
        );
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        setPermissionsTeamId($tenant->id);
    }
}
