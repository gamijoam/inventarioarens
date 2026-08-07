import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PromotionsManager } from '@/features/promotions/PromotionsManager';
import { PERMISSIONS } from '@/permissions/constants';

export const Route = createFileRoute('/_authed/promotions')({
  component: PromotionsPage,
});

function PromotionsPage() {
  return (
    <PageLayout
      title="Promociones"
      description="Crea combos y precios promocionales que el POS puede aplicar al ticket."
    >
      <Can I={PERMISSIONS.PROMOTIONS_VIEW}>
        <PromotionsManager />
      </Can>
    </PageLayout>
  );
}
