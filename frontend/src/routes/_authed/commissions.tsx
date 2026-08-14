import { createFileRoute } from '@tanstack/react-router';
import { BadgeDollarSign } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { CommissionsManager } from '@/features/commissions/CommissionsManager';
import { CommissionLedgerPanel } from '@/features/commissions/CommissionLedgerPanel';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';

export const Route = createFileRoute('/_authed/commissions')({ component: CommissionsPage });

function CommissionsPage() {
  const canViewAll = useCan(PERMISSIONS.COMMISSIONS_VIEW_ALL);

  return (
    <PageLayout
      title="Comisiones"
      description="Define cómo ganan vendedores y cajeros, elige la tasa y valida cada escenario antes de activarlo."
      icon={<BadgeDollarSign className="text-primary size-6" />}
    >
      <div className="space-y-7">
        {canViewAll && <CommissionsManager />}
        <div>
          <h2 className="mb-3 text-lg font-semibold">{canViewAll ? 'Movimientos de comisión' : 'Mis comisiones'}</h2>
          <CommissionLedgerPanel ownOnly={!canViewAll} />
        </div>
      </div>
    </PageLayout>
  );
}
