<?php

namespace App\Modules\ReportsV2;

use Illuminate\Support\Facades\DB;

/**
 * Catalogo de reportes V2. Cada definicion es declarativa: fuente, medidas,
 * dimensiones y filtros. El runner las convierte en una sola query SQL.
 */
class ReportRegistry
{
    /** @var array<string, ReportDefinition>|null */
    private ?array $definitions = null;

    public function get(string $code): ?ReportDefinition
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * @return array<string, ReportDefinition>
     */
    public function all(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $this->definitions = collect($this->definitionsData())
            ->mapWithKeys(fn (array $data, string $code): array => [
                $code => new ReportDefinition($code, ...$data),
            ])
            ->all();

        return $this->definitions;
    }

    /**
     * Dimensiones de periodo (dia/semana/mes) sobre una columna de fecha.
     *
     * @return array<string, array{expr: string, label: string}>
     */
    private function periodDimensions(string $dateColumn): array
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            $weekStart = "date({$dateColumn}, '-' || ((cast(strftime('%w', {$dateColumn}) as integer) + 6) % 7) || ' days')";

            return [
                'day' => [
                    'expr' => "strftime('%Y-%m-%d', {$dateColumn})",
                    'label' => "strftime('%Y-%m-%d', {$dateColumn})",
                ],
                'week' => [
                    'expr' => $weekStart,
                    'label' => $weekStart,
                ],
                'month' => [
                    'expr' => "strftime('%Y-%m', {$dateColumn})",
                    'label' => "strftime('%Y-%m', {$dateColumn})",
                ],
            ];
        }

        return [
            'day' => [
                'expr' => "to_char({$dateColumn}, 'YYYY-MM-DD')",
                'label' => "to_char({$dateColumn}, 'YYYY-MM-DD')",
            ],
            'week' => [
                'expr' => "to_char(date_trunc('week', {$dateColumn}), 'YYYY-MM-DD')",
                'label' => "to_char(date_trunc('week', {$dateColumn}), 'YYYY-MM-DD')",
            ],
            'month' => [
                'expr' => "to_char(date_trunc('month', {$dateColumn}), 'YYYY-MM')",
                'label' => "to_char(date_trunc('month', {$dateColumn}), 'YYYY-MM')",
            ],
        ];
    }

    /**
     * Dimension de empresa (tenant) sobre el alias base del reporte.
     *
     * @return array{expr: string, label: string, join: string}
     */
    private function companyDimension(string $alias): array
    {
        return [
            'expr' => 't.id',
            'label' => 't.name',
            'join' => "join tenants t on t.id = {$alias}.tenant_id",
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function definitionsData(): array
    {
        return [
            'sales_overview' => [
                'name' => 'Ventas por período',
                'domain' => 'ventas',
                'permission' => 'reports.sales.view',
                'orgSupported' => true,
                'base' => "sales s left join (
                    select po.sale_id, po.tenant_id,
                      coalesce(sum(case when pp.currency = 'USD' then pp.amount_base else 0 end), 0) as usd_paid,
                      coalesce(sum(case when pp.currency = 'VES' then pp.amount_local else 0 end), 0) as ves_paid,
                      coalesce(sum(case when pp.currency = 'VES' then pp.amount_base else 0 end), 0) as usd_equiv,
                      coalesce(sum(case when pp.currency = 'USD' then pp.amount_local else 0 end), 0) as ves_equiv
                    from pos_payments pp join pos_orders po on po.id = pp.pos_order_id and po.tenant_id = pp.tenant_id
                    where pp.status = 'captured'
                    group by po.sale_id, po.tenant_id
                  ) pay on pay.sale_id = s.id and pay.tenant_id = s.tenant_id
                  left join (
                    select sale_id, tenant_id,
                      coalesce(sum(reversed_base_amount), 0) as reversed_base_amount,
                      coalesce(sum(reversed_local_amount), 0) as reversed_local_amount,
                      count(*) as reversal_count
                    from sale_reversals
                    group by sale_id, tenant_id
                  ) rev on rev.sale_id = s.id and rev.tenant_id = s.tenant_id",
                'dateColumn' => 's.confirmed_at',
                'statusSql' => "s.status in ('confirmed', 'voided')",
                'measures' => [
                    'sales_total' => 'coalesce(sum(s.total_base_amount), 0)',
                    'reversed_total' => 'coalesce(sum(coalesce(rev.reversed_base_amount, 0)), 0)',
                    'net_sales_total' => 'coalesce(sum(s.total_base_amount - coalesce(rev.reversed_base_amount, 0)), 0)',
                    'reversed_local_total' => 'coalesce(sum(coalesce(rev.reversed_local_amount, 0)), 0)',
                    'reversal_count' => 'coalesce(sum(coalesce(rev.reversal_count, 0)), 0)',
                    'usd_paid' => 'coalesce(sum(pay.usd_paid), 0)',
                    'ves_paid' => 'coalesce(sum(pay.ves_paid), 0)',
                    'usd_equiv' => 'coalesce(sum(pay.usd_equiv), 0)',
                    'sales_count' => 'count(s.id)',
                    'ticket_avg' => 'coalesce(avg(s.total_base_amount), 0)',
                    'total_usd' => 'coalesce(sum(pay.usd_paid + pay.usd_equiv), 0)',
                    'total_ves' => 'coalesce(sum(pay.ves_paid + pay.ves_equiv), 0)',
                ],
                'defaultMeasure' => 'sales_total',
                'dimensions' => array_merge(
                    $this->periodDimensions('s.confirmed_at'),
                    ['company' => $this->companyDimension('s')],
                ),
                'defaultDimension' => 'day',
                'averageMeasures' => ['ticket_avg' => 'sales_count'],
                'localPairs' => ['total_ves' => 'total_usd'],
                'hiddenMeasures' => ['total_usd', 'total_ves'],
            ],
            'sales_by_product' => [
                'name' => 'Ventas por producto',
                'domain' => 'ventas',
                'permission' => 'reports.sales.view',
                'orgSupported' => true,
                'base' => 'sale_items si join sales s on s.id = si.sale_id and s.tenant_id = si.tenant_id',
                'dateColumn' => 's.confirmed_at',
                'statusSql' => "s.status in ('confirmed', 'voided')",
                'measures' => [
                    'units' => 'sum(si.quantity)',
                    'amount' => 'sum(si.base_total_amount)',
                    'sales_count' => 'count(distinct s.id)',
                ],
                'defaultMeasure' => 'amount',
                'dimensions' => [
                    'product' => [
                        'expr' => 'p.id',
                        'label' => 'p.name',
                        'join' => 'join products p on p.id = si.product_id and p.tenant_id = si.tenant_id',
                    ],
                    'cashier' => [
                        'expr' => 'u.id',
                        'label' => 'u.name',
                        'join' => 'left join users u on u.id = s.created_by',
                    ],
                    'company' => $this->companyDimension('si'),
                ],
                'defaultDimension' => 'product',
            ],
            'sales_by_payment_method' => [
                'name' => 'Ventas por método de pago',
                'domain' => 'ventas',
                'permission' => 'reports.cash.view',
                'orgSupported' => true,
                'base' => "pos_payments pp join pos_orders po on po.id = pp.pos_order_id and po.tenant_id = pp.tenant_id and pp.status = 'captured'",
                'dateColumn' => 'po.paid_at',
                'statusSql' => "po.status = 'paid'",
                'measures' => [
                    'usd_paid' => "coalesce(sum(case when pp.currency = 'USD' then pp.amount_base else 0 end), 0)",
                    'ves_paid' => "coalesce(sum(case when pp.currency = 'VES' then pp.amount_local else 0 end), 0)",
                    'usd_equiv' => "coalesce(sum(case when pp.currency = 'VES' then pp.amount_base else 0 end), 0)",
                    'orders_count' => 'count(distinct po.id)',
                    'total_usd' => 'coalesce(sum(pp.amount_base), 0)',
                    'total_ves' => 'coalesce(sum(pp.amount_local), 0)',
                ],
                'defaultMeasure' => 'usd_equiv',
                'dimensions' => [
                    'method' => ['expr' => 'pp.method', 'label' => 'pp.method'],
                    'company' => $this->companyDimension('pp'),
                ],
                'defaultDimension' => 'method',
                'localPairs' => ['total_ves' => 'total_usd'],
                'hiddenMeasures' => ['total_usd', 'total_ves'],
            ],
            'sales_by_company' => [
                'name' => 'Ventas por empresa',
                'domain' => 'ventas',
                'permission' => 'reports.sales.view',
                'orgSupported' => true,
                'base' => "sales s left join (
                    select po.sale_id, po.tenant_id,
                      coalesce(sum(case when pp.currency = 'USD' then pp.amount_base else 0 end), 0) as usd_paid,
                      coalesce(sum(case when pp.currency = 'VES' then pp.amount_local else 0 end), 0) as ves_paid,
                      coalesce(sum(case when pp.currency = 'VES' then pp.amount_base else 0 end), 0) as usd_equiv,
                      coalesce(sum(case when pp.currency = 'USD' then pp.amount_local else 0 end), 0) as ves_equiv
                    from pos_payments pp join pos_orders po on po.id = pp.pos_order_id and po.tenant_id = pp.tenant_id
                    where pp.status = 'captured'
                    group by po.sale_id, po.tenant_id
                  ) pay on pay.sale_id = s.id and pay.tenant_id = s.tenant_id
                  left join (
                    select sale_id, tenant_id,
                      coalesce(sum(reversed_base_amount), 0) as reversed_base_amount,
                      coalesce(sum(reversed_local_amount), 0) as reversed_local_amount,
                      count(*) as reversal_count
                    from sale_reversals
                    group by sale_id, tenant_id
                  ) rev on rev.sale_id = s.id and rev.tenant_id = s.tenant_id",
                'dateColumn' => 's.confirmed_at',
                'statusSql' => "s.status in ('confirmed', 'voided')",
                'measures' => [
                    'sales_total' => 'coalesce(sum(s.total_base_amount), 0)',
                    'reversed_total' => 'coalesce(sum(coalesce(rev.reversed_base_amount, 0)), 0)',
                    'net_sales_total' => 'coalesce(sum(s.total_base_amount - coalesce(rev.reversed_base_amount, 0)), 0)',
                    'reversed_local_total' => 'coalesce(sum(coalesce(rev.reversed_local_amount, 0)), 0)',
                    'reversal_count' => 'coalesce(sum(coalesce(rev.reversal_count, 0)), 0)',
                    'usd_paid' => 'coalesce(sum(pay.usd_paid), 0)',
                    'ves_paid' => 'coalesce(sum(pay.ves_paid), 0)',
                    'usd_equiv' => 'coalesce(sum(pay.usd_equiv), 0)',
                    'sales_count' => 'count(s.id)',
                    'total_usd' => 'coalesce(sum(pay.usd_paid + pay.usd_equiv), 0)',
                    'total_ves' => 'coalesce(sum(pay.ves_paid + pay.ves_equiv), 0)',
                ],
                'defaultMeasure' => 'sales_total',
                'dimensions' => [
                    'company' => $this->companyDimension('s'),
                ],
                'defaultDimension' => 'company',
                'localPairs' => ['total_ves' => 'total_usd'],
                'hiddenMeasures' => ['total_usd', 'total_ves'],
            ],
            'stock_by_product' => [
                'name' => 'Stock por producto',
                'domain' => 'inventario',
                'permission' => 'reports.inventory.view',
                'orgSupported' => true,
                'base' => 'stock_balances sb join products p on p.id = sb.product_id and p.tenant_id = sb.tenant_id',
                'dateColumn' => null,
                'statusSql' => '1 = 1',
                'measures' => [
                    'stock_qty' => 'sum(sb.quantity_available)',
                    'stock_value' => 'sum(sb.quantity_available * coalesce(p.last_purchase_cost, p.average_cost, 0))',
                ],
                'defaultMeasure' => 'stock_qty',
                'dimensions' => [
                    'product' => [
                        'expr' => 'p.id',
                        'label' => 'p.name',
                    ],
                    'warehouse' => [
                        'expr' => 'w.id',
                        'label' => 'w.name',
                        'join' => 'join warehouses w on w.id = sb.warehouse_id and w.tenant_id = sb.tenant_id',
                    ],
                    'company' => $this->companyDimension('sb'),
                ],
                'defaultDimension' => 'product',
                'equalityFilters' => ['warehouse_id' => 'sb.warehouse_id'],
                'lowStockFilter' => true,
            ],
            'stock_by_warehouse' => [
                'name' => 'Stock por almacén',
                'domain' => 'inventario',
                'permission' => 'reports.inventory.view',
                'orgSupported' => true,
                'base' => 'stock_balances sb join products p on p.id = sb.product_id and p.tenant_id = sb.tenant_id',
                'dateColumn' => null,
                'statusSql' => '1 = 1',
                'measures' => [
                    'stock_qty' => 'sum(sb.quantity_available)',
                    'stock_value' => 'sum(sb.quantity_available * coalesce(p.last_purchase_cost, p.average_cost, 0))',
                ],
                'defaultMeasure' => 'stock_qty',
                'dimensions' => [
                    'warehouse' => [
                        'expr' => 'w.id',
                        'label' => 'w.name',
                        'join' => 'join warehouses w on w.id = sb.warehouse_id and w.tenant_id = sb.tenant_id',
                    ],
                    'company' => $this->companyDimension('sb'),
                ],
                'defaultDimension' => 'warehouse',
            ],
            'receivables_by_customer' => [
                'name' => 'Cuentas por cobrar por cliente',
                'domain' => 'finanzas',
                'permission' => 'finance_reports.view',
                'orgSupported' => true,
                'base' => 'accounts_receivables ar join customers c on c.id = ar.customer_id and c.tenant_id = ar.tenant_id',
                'dateColumn' => 'ar.opened_at',
                'statusSql' => "ar.status in ('pending', 'partial', 'overdue')",
                'measures' => [
                    'balance' => 'sum(ar.balance_base_amount)',
                    'count' => 'count(ar.id)',
                ],
                'defaultMeasure' => 'balance',
                'dimensions' => [
                    'customer' => ['expr' => 'c.id', 'label' => 'c.name'],
                    'company' => $this->companyDimension('ar'),
                ],
                'defaultDimension' => 'customer',
            ],
            'payables_by_supplier' => [
                'name' => 'Cuentas por pagar por proveedor',
                'domain' => 'finanzas',
                'permission' => 'finance_reports.view',
                'orgSupported' => true,
                'base' => 'accounts_payables ap join suppliers sup on sup.id = ap.supplier_id and sup.tenant_id = ap.tenant_id',
                'dateColumn' => 'ap.opened_at',
                'statusSql' => "ap.status in ('pending', 'partial', 'overdue')",
                'measures' => [
                    'balance' => 'sum(ap.balance_base_amount)',
                    'count' => 'count(ap.id)',
                ],
                'defaultMeasure' => 'balance',
                'dimensions' => [
                    'supplier' => ['expr' => 'sup.id', 'label' => 'sup.name'],
                    'company' => $this->companyDimension('ap'),
                ],
                'defaultDimension' => 'supplier',
            ],
        ];
    }
}
