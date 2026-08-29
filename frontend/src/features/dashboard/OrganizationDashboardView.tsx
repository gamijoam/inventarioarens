import { Boxes, Landmark, Receipt, ShoppingCart, Wallet } from 'lucide-react';
import { useState } from 'react';

import { useAuth } from '@/auth/useAuth';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/cn';

import type { OrganizationDashboard } from './organizationApi';

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

  return (
    <div className="space-y-6">
      <section className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-6">
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

      <Card>
        <CardHeader>
          <CardTitle>Empresas del grupo</CardTitle>
        </CardHeader>
        <CardContent className="overflow-auto">
          <table className="w-full min-w-[900px] text-sm">
            <thead className="bg-bg text-text-muted text-left text-xs uppercase">
              <tr>
                <th className="px-3 py-2">Empresa</th>
                <th className="px-3 py-2 text-right">Ventas</th>
                <th className="px-3 py-2 text-right">Tickets</th>
                <th className="px-3 py-2 text-right">POS</th>
                <th className="px-3 py-2 text-center">Cajas</th>
                <th className="px-3 py-2 text-center">Bajo stock</th>
                <th className="px-3 py-2 text-right">CxC</th>
                <th className="px-3 py-2 text-right">CxP</th>
                <th className="px-3 py-2 text-right">Acción</th>
              </tr>
            </thead>
            <tbody className="divide-border divide-y">
              {data.companies.map((company) => (
                <tr key={company.tenant_id}>
                  <td className="px-3 py-2">
                    <div className="font-medium">{company.name}</div>
                    <div className="text-text-muted text-xs">{company.slug}</div>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {formatMoney(company.sales.total_base_amount)}
                    <div className="text-text-muted text-xs">{company.sales.confirmed_count} ventas</div>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {company.pos.paid_orders_count}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {formatMoney(company.pos.paid_base_amount)}
                  </td>
                  <td className="px-3 py-2 text-center">
                    <Badge
                      variant={company.cash_register.open_sessions_count > 0 ? 'success' : 'default'}
                    >
                      {company.cash_register.open_sessions_count}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-center">
                    <Badge variant={company.inventory.low_stock_count > 0 ? 'danger' : 'default'}>
                      {company.inventory.low_stock_count}
                    </Badge>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {formatMoney(company.finance.accounts_receivable_balance_base_amount)}
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">
                    {formatMoney(company.finance.accounts_payable_balance_base_amount)}
                  </td>
                  <td className="px-3 py-2 text-right">
                    <Button
                      variant="outline"
                      size="sm"
                      disabled={switching === company.slug}
                      onClick={() => void enterCompany(company.slug)}
                    >
                      {switching === company.slug ? 'Cambiando...' : 'Entrar'}
                    </Button>
                  </td>
                </tr>
              ))}
              {data.companies.length === 0 && (
                <tr>
                  <td colSpan={9} className="text-text-muted px-3 py-6 text-center">
                    No hay empresas hijas en este grupo todavía.
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </CardContent>
      </Card>

      <div className="border-border rounded-md border p-3">
        <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
          <Info
            label="Balance operativo del grupo"
            value={formatMoney(balance)}
            tone={balance >= 0 ? 'text-success' : 'text-danger'}
          />
          <Info
            label="Ventas promedio por ticket"
            value={formatMoney(
              data.totals.pos_orders_count > 0
                ? data.totals.pos_paid_base_amount / data.totals.pos_orders_count
                : 0,
            )}
          />
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
  const toneClasses = {
    primary: 'text-primary',
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    info: 'text-info',
    default: 'text-text-primary',
  } as const;

  return (
    <Card>
      <CardContent className="flex items-start justify-between gap-3 p-4">
        <div className="min-w-0">
          <p className="text-text-muted text-xs font-medium uppercase">{title}</p>
          <p className={cn('mt-1 text-2xl font-semibold tabular-nums', toneClasses[tone])}>
            {value}
          </p>
          <p className="text-text-muted mt-1 text-xs">{helper}</p>
        </div>
        <div className={cn('bg-bg shrink-0 rounded-md p-2', toneClasses[tone])}>
          <Icon className="size-5" aria-hidden="true" />
        </div>
      </CardContent>
    </Card>
  );
}

function Info({ label, value, tone = 'text-text-primary' }: { label: string; value: string; tone?: string }) {
  return (
    <div className="border-border rounded-md border p-3">
      <div className="text-text-muted text-xs uppercase">{label}</div>
      <div className={cn('mt-1 font-semibold tabular-nums', tone)}>{value}</div>
    </div>
  );
}
