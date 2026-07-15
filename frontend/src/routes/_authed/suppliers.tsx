/**
 * Pagina /suppliers: gestion de proveedores.
 * Implementa busqueda, filtro activo/inactivo, CRUD.
 */
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { SuppliersManager } from '@/features/suppliers/SuppliersManager';

export const Route = createFileRoute('/_authed/suppliers')({
  component: SuppliersPage,
});

function SuppliersPage() {
  return (
    <PageLayout
      title="Proveedores"
      description="Gestion de proveedores de la empresa. Necesarios para ordenes de compra y CxP."
    >
      <SuppliersManager />
    </PageLayout>
  );
}
