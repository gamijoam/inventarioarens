/**
 * Pagina /customers: gestion de clientes.
 * Implementa busqueda, filtro activo/inactivo, CRUD.
 */
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { CustomersManager } from '@/features/customers/CustomersManager';

export const Route = createFileRoute('/_authed/customers')({
  component: CustomersPage,
});

function CustomersPage() {
  return (
    <PageLayout
      title="Clientes"
      description="Gestion de clientes de la empresa. Necesarios para ventas POS, CxC y cotizaciones."
    >
      <CustomersManager />
    </PageLayout>
  );
}
