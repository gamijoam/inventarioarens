import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';
import { BadgeDollarSign } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { CommissionsManager } from '@/features/commissions/CommissionsManager';
import { CommissionControlPanel } from '@/features/commissions/CommissionControlPanel';
import { CommissionLedgerPanel } from '@/features/commissions/CommissionLedgerPanel';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';

export const Route = createFileRoute('/_authed/commissions')({ component: CommissionsPage });

function CommissionsPage() {
  const canViewAll = useCan(PERMISSIONS.COMMISSIONS_VIEW_ALL);
  const [activeTab, setActiveTab] = useState<'control' | 'plans' | 'ledger'>('control');

  return (
    <PageLayout
      title="Comisiones"
      description="Define cómo ganan vendedores y cajeros, elige la tasa y valida cada escenario antes de activarlo."
      icon={<BadgeDollarSign className="text-primary size-6" />}
    >
      <div className="space-y-7">
        <nav
          className="border-border bg-surface flex flex-wrap gap-1 rounded-xl border p-1"
          aria-label="Secciones de comisiones"
        >
          <CommissionTab active={activeTab === 'control'} onClick={() => setActiveTab('control')}>
            Control de ventas
          </CommissionTab>
          {canViewAll && (
            <CommissionTab active={activeTab === 'plans'} onClick={() => setActiveTab('plans')}>
              Planes y simulador
            </CommissionTab>
          )}
          <CommissionTab active={activeTab === 'ledger'} onClick={() => setActiveTab('ledger')}>
            Movimientos y pagos
          </CommissionTab>
        </nav>

        {activeTab === 'control' && <CommissionControlPanel ownOnly={!canViewAll} />}
        {activeTab === 'plans' && canViewAll && <CommissionsManager />}
        {activeTab === 'ledger' && (
          <div>
            <h2 className="mb-3 text-lg font-semibold">
              {canViewAll ? 'Movimientos de comisión' : 'Mis comisiones'}
            </h2>
            <CommissionLedgerPanel ownOnly={!canViewAll} />
          </div>
        )}
      </div>
    </PageLayout>
  );
}

function CommissionTab({
  active,
  onClick,
  children,
}: {
  active: boolean;
  onClick: () => void;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      className={`rounded-lg px-4 py-2 text-sm font-medium transition ${active ? 'bg-primary text-primary-foreground' : 'text-text-muted hover:bg-bg hover:text-text-primary'}`}
      aria-pressed={active}
      onClick={onClick}
    >
      {children}
    </button>
  );
}
