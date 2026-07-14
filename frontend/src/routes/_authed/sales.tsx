import { createFileRoute } from '@tanstack/react-router';
import { Construction } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';

export const Route = createFileRoute('/_authed/sales')({
  component: SalesPlaceholderPage,
});

function SalesPlaceholderPage() {
  return (
    <PageLayout title="Ventas" description="Próximamente en Fase 2.">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Construction className="size-4" aria-hidden="true" />
            En construcción
          </CardTitle>
          <CardDescription>
            El módulo de ventas se implementa en la Fase 2 del roadmap. Por ahora puedes ver el
            estado actual del backend en el menú lateral (Inventario).
          </CardDescription>
        </CardHeader>
        <CardContent className="text-sm text-text-muted">
          Próximamente: listado de órdenes POS, detalle, KPIs por sucursal/cajero.
        </CardContent>
      </Card>
    </PageLayout>
  );
}