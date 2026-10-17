/**
 * Pagina /inventory/price-lists: gestion de listas de precios.
 */
import { createFileRoute, Link } from '@tanstack/react-router';
import { ArrowLeft, ListOrdered } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { PriceListsManager } from '@/features/inventory-center/catalogs/PriceListsManager';

export const Route = createFileRoute('/_authed/inventory/price-lists')({
  component: PriceListsPage,
});

function PriceListsPage() {
  return (
    <PageLayout
      title="Listas de precios"
      description="Organiza precios especiales y condiciones comerciales por cliente."
      actions={
        <Button variant="outline" size="sm" asChild>
          <Link
            to="/inventory"
            search={{
              search: '',
              tracking: 'all',
              stock: 'all',
              status: 'active',
              page: 1,
              brand_id: undefined,
              category_id: undefined,
              tag_id: undefined,
              warehouse_id: undefined,
              low_stock_threshold: undefined,
              sort_by: undefined,
              sort_dir: undefined,
            }}
          >
            <ArrowLeft className="size-4" aria-hidden="true" />
            Volver al inventario
          </Link>
        </Button>
      }
    >
      <div className="text-muted-foreground mb-4 flex items-center gap-2 text-[length:var(--text-ui-body)]">
        <ListOrdered className="size-5" aria-hidden="true" />
        Configura listas para aplicar reglas de precios de forma consistente.
      </div>
      <PriceListsManager />
    </PageLayout>
  );
}
