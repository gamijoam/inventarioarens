import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { SalesReturnsManager } from '@/features/sales-returns/SalesReturnsManager';

export const Route = createFileRoute('/_authed/sales-returns')({
  component: SalesReturnsPage,
});

function SalesReturnsPage() {
  return (
    <PageLayout
      title="Devoluciones de venta"
      description="Audita productos devueltos, seriales, condiciones y movimientos de inventario."
    >
      <SalesReturnsManager />
    </PageLayout>
  );
}
