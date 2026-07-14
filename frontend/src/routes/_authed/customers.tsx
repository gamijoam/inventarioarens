import { createFileRoute } from '@tanstack/react-router';
import { Construction } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';

export const Route = createFileRoute('/_authed/customers')({
  component: CustomersPlaceholderPage,
});

function CustomersPlaceholderPage() {
  return (
    <PageLayout title="Clientes" description="Próximamente en Fase 3.">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Construction className="size-4" aria-hidden="true" />
            En construcción
          </CardTitle>
          <CardDescription>
            El módulo de clientes se implementa en la Fase 3 del roadmap.
          </CardDescription>
        </CardHeader>
        <CardContent className="text-sm text-text-muted">
          Próximamente: listado, búsqueda, creación rápida, historial POS, saldos CxC.
        </CardContent>
      </Card>
    </PageLayout>
  );
}