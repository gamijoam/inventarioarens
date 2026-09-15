import { Boxes, CircleDollarSign, Landmark, PieChart, Receipt, ShoppingCart, Wallet } from 'lucide-react';
import { useState } from 'react';

import { useAuth } from '@/auth/useAuth';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/cn';

import type { OrganizationDashboard } from './organizationApi';
import { getMetricCardSizeClass } from './dashboardConfig';

interface OrganizationDashboardViewProps {
  data: OrganizationDashboard;
}

export function OrganizationDashboardView({ data }: OrganizationDashboardViewProps) {
  const { switchTo } = useAuth();
  const [switching, setSwitching] = useState<string | null>(null);

  async function enterCompany(slug: string): Promise<void> {
    if (switching) return;
    setSwitching(slug);
    try {
      await switchTo(slug);
    } finally {
      setSwitching(null);
    }
  }

  const balance = data.totals.receivable_balance_base_amount - data.totals.payable_balance_base_amount;
  const orgCostVal = data.totals.stock_cost_value ?? 0;
  const orgRetailVal = data.totals.stock_retail_value ?? 0;
  const orgUnits = data.totals.stock_total_units ?? 0;
  const orgInventoryValue = orgCostVal > 0 ? orgCostVal : orgRetailVal;
  const orgInventoryHelper = orgCostVal > 0
    ? `${orgUnits} unid. · PVP: ${formatMoney(orgRetailVal)}`
    : `${orgUnits} unid. en el grupo`;

  return (
    <div className="space-y-6">
      <section className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4">
        <MetricCard
          title="Ventas del grupo"
          icon={ShoppingCart}
          value={formatMoney(data.totals.sales_total_base_amount)}
          helper={`${data.totals.sales_count} confirmadas`}
          tone="primary"
        />
        <MetricCard
          title="POS cobrado"
          icon={Wallet}
          value={formatMoney(data.totals.pos_paid_base_amount)}
          helper={`${data.totals.pos_orders_count} tickets`}
          tone="success"
        />
        <MetricCard
          title="Cajas abiertas"
          icon={Receipt}
          value={String(data.totals.open_cash_sessions)}
          helper="En todas las sucursales"
          tone="info"
        />
        <MetricCard
          title="Valor inventario"
          icon={CircleDollarSign}
          value={formatMoney(orgInventoryValue)}
          helper={orgInventoryHelper}
          tone="success"
        />
        <MetricCard
          title="Bajo stock"
          icon={Boxes}
          value={String(data.totals.low_stock_count)}
          helper="En todas las sucursales"
          tone={data.totals.low_stock_count > 0 ? 'danger' : 'default'}
        />
        <MetricCard
          title="CxC abierta"
          icon={Wallet}
          value={formatMoney(data.totals.receivable_balance_base_amount)}
          helper="Total de cuentas por cobrar"
          tone="warning"
        />
        <MetricCard
          title="CxP abierta"
          icon={Landmark}
          value={formatMoney(data.totals.payable_balance_base_amount)}
          helper="Total de cuentas por pagar"
          tone="danger"
        />
      </section>

      {/* Balance Operativo y Ventas Promedio por Ticket */}
      <section className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div className="bg-white p-5 rounded-2xl border border-slate-200/90 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Balance operativo del grupo</p>
            <h3 className={cn('text-2xl font-black font-mono mt-1', balance >= 0 ? 'text-emerald-600' : 'text-rose-600')}>
              {formatMoney(balance)}
            </h3>
            <p className="text-[11px] text-slate-500 mt-0.5">Ingresos operacionales netos tras deducción de egresos.</p>
          </div>
          <div className="size-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center text-xl font-bold border border-emerald-200 shrink-0">
            <PieChart className="size-6" />
          </div>
        </div>
        <div className="bg-white p-5 rounded-2xl border border-slate-200/90 shadow-sm flex items-center justify-between">
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-slate-400">Ventas promedio por ticket</p>
            <h3 className="text-2xl font-black text-slate-900 font-mono mt-1">
              {formatMoney(
                data.totals.pos_orders_count > 0
                  ? data.totals.pos_paid_base_amount / data.totals.pos_orders_count
                  : 0,
              )}
            </h3>
            <p className="text-[11px] text-slate-500 mt-0.5">Ticket medio facturado en el rango de fecha seleccionado.</p>
          </div>
          <div className="size-12 rounded-xl bg-amber-50 text-orange-600 flex items-center justify-center text-xl font-bold border border-amber-200 shrink-0">
            <Receipt className="size-6" />
          </div>
        </div>
      </section>

      <div className="bg-white rounded-2xl border border-slate-200/90 shadow-sm overflow-hidden">
        <div className="p-5 border-b border-slate-100 flex items-center justify-between bg-gradient-to-r from-amber-50/40 to-transparent">
          <div>
            <h4 className="font-bold text-slate-900 text-sm">Empresas del grupo</h4>
            <p className="text-xs text-slate-500">Distribución de operaciones y rendimiento individual por sede.</p>
          </div>
          <span className="text-xs bg-slate-100 text-slate-700 font-semibold px-2.5 py-1 rounded-full border border-slate-200">
            {data.companies.length} {data.companies.length === 1 ? 'Empresa' : 'Empresas'}
          </span>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full min-w-[900px] text-sm text-left">
            <thead className="bg-slate-50/80 text-[11px] font-bold text-slate-500 uppercase tracking-wider border-b border-slate-200/80">
              <tr>
                <th className="py-3 px-4">Empresa</th>
                <th className="py-3 px-4 text-right">Ventas</th>
                <th className="py-3 px-4 text-right">Tickets</th>
                <th className="py-3 px-4 text-right">POS</th>
                <th className="py-3 px-4 text-center">Cajas</th>
                <th className="py-3 px-4 text-center">Bajo stock</th>
                <th className="py-3 px-4 text-right">CxC</th>
                <th className="py-3 px-4 text-right">CxP</th>
                <th className="py-3 px-4 text-center">Acción</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.companies.map((company) => (
                <tr key={company.tenant_id} className="hover:bg-amber-50/30 font-medium text-slate-700 transition-colors">
                  <td className="py-3.5 px-4 font-bold text-slate-900 flex items-center gap-2.5">
                    <span className="size-2 rounded-full bg-emerald-500 shrink-0" />
                    <div>
                      <div>{company.name}</div>
                      <div className="text-slate-400 text-xs font-normal font-mono">{company.slug}</div>
                    </div>
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums font-mono font-bold text-slate-900">
                    {formatMoney(company.sales.total_base_amount)}
                    <div className="text-slate-400 text-[11px] font-normal">{company.sales.confirmed_count} ventas</div>
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums font-mono font-medium text-slate-700">
                    {company.pos.paid_orders_count}
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums font-mono font-bold text-emerald-600">
                    {formatMoney(company.pos.paid_base_amount)}
                  </td>
                  <td className="py-3.5 px-4 text-center">
                    <Badge
                      variant={company.cash_register.open_sessions_count > 0 ? 'success' : 'default'}
                      className="rounded-lg"
                    >
                      {company.cash_register.open_sessions_count}
                    </Badge>
                  </td>
                  <td className="py-3.5 px-4 text-center">
                    <Badge variant={company.inventory.low_stock_count > 0 ? 'danger' : 'default'} className="rounded-lg">
                      {company.inventory.low_stock_count}
                    </Badge>
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums font-mono font-semibold text-amber-600">
                    {formatMoney(company.finance.accounts_receivable_balance_base_amount)}
                  </td>
                  <td className="py-3.5 px-4 text-right tabular-nums font-mono font-semibold text-rose-600">
                    {formatMoney(company.finance.accounts_payable_balance_base_amount)}
                  </td>
                  <td className="py-3.5 px-4 text-center">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={switching === company.slug}
                      onClick={() => void enterCompany(company.slug)}
                      className="rounded-xl font-bold text-orange-600 hover:text-orange-700 border-orange-200 hover:bg-orange-50 text-xs"
                    >
                      {switching === company.slug ? 'Cambiando...' : 'Entrar'}
                    </Button>
                  </td>
                </tr>
              ))}
              {data.companies.length === 0 && (
                <tr>
                  <td colSpan={9} className="text-slate-400 px-3 py-6 text-center text-xs">
                    No hay empresas hijas en este grupo todavía.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}

interface MetricCardProps {
  title: string;
  icon: React.ComponentType<{ className?: string }>;
  value: string;
  helper: string;
  tone: 'primary' | 'success' | 'warning' | 'danger' | 'info' | 'default';
}

function MetricCard({ title, icon: Icon, value, helper, tone }: MetricCardProps) {
  const toneConfig = {
    primary: {
      value: 'text-slate-900',
      box: 'bg-orange-50 border-orange-200 text-orange-600',
      stripe: 'bg-gradient-to-r from-orange-500 to-amber-400',
    },
    success: {
      value: 'text-emerald-600',
      box: 'bg-emerald-50 border-emerald-200 text-emerald-600',
      stripe: 'bg-emerald-500',
    },
    warning: {
      value: 'text-amber-600',
      box: 'bg-amber-50 border-amber-200 text-amber-600',
      stripe: 'bg-amber-400',
    },
    danger: {
      value: 'text-rose-600',
      box: 'bg-rose-50 border-rose-200 text-rose-600',
      stripe: 'bg-rose-500',
    },
    info: {
      value: 'text-sky-600',
      box: 'bg-sky-50 border-sky-200 text-sky-600',
      stripe: 'bg-sky-500',
    },
    default: {
      value: 'text-slate-900',
      box: 'bg-slate-50 border-slate-200 text-slate-600',
      stripe: 'bg-slate-300',
    },
  } as const;

  const config = toneConfig[tone] ?? toneConfig.default;
  const size = getMetricCardSizeClass(7);

  return (
    <div className={cn('bg-white border border-slate-200/90 relative overflow-hidden group transition-all', size.card)}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <span className={size.title}>{title}</span>
          <span className={cn(size.value, config.value)}>
            {value}
          </span>
        </div>
        <div className={cn(size.iconBox, 'group-hover:scale-110 transition-transform', config.box)}>
          <Icon className={size.icon} aria-hidden="true" />
        </div>
      </div>
      <div className={size.helper}>
        <span className="size-2 rounded-full bg-slate-300 mr-2 shrink-0" />
        <span className="truncate">{helper}</span>
      </div>
      <div className={cn('absolute bottom-0 left-0 right-0', size.stripe, config.stripe)} />
    </div>
  );
}

