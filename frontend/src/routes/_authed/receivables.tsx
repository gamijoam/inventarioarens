import { createFileRoute } from '@tanstack/react-router';
import { Construction } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';

export const Route = createFileRoute('/_authed/receivables')({
  component: ReceivablesPlaceholderPage,
});

function ReceivablesPlaceholderPage() {
  return (
    <PageLayout title="Cuentas por cobrar" description="Próximamente en Fase 3.">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Construction className="size-4" aria-hidden="true" />
            En construcción
          </CardTitle>
          <CardDescription>
            El módulo de CxC se implementa en la Fase 3 del roadmap.
          </CardDescription>
        </CardHeader>
        <CardContent className="text-sm text-text-muted">
          Próximamente: listado con filtros (cliente, status, vencimiento), formulario de cobro con
          snapshot de tasa.
        </CardContent>
      </Card>
    </PageLayout>
  );
}