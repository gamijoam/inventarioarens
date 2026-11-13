import { createFileRoute } from '@tanstack/react-router';
import { CalendarClock } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { ExpirationsPanel } from '@/features/expirations/ExpirationsPanel';

export const Route = createFileRoute('/_authed/inventory/expirations')({
  component: ExpirationsPage,
});

function ExpirationsPage() {
  return (
    <PageLayout
      title="Vencimientos"
      description="Lotes por vencer y vencidos, con su valor, para retirarlos o darlos de baja como merma."
      icon={<CalendarClock className="size-6" />}
    >
      <ExpirationsPanel />
    </PageLayout>
  );
}
