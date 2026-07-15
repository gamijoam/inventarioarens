/**
 * Pagina /inventory/admin: catalogos administrativos de la empresa.
 * Incluye sucursales, almacenes, politicas de garantia y listas
 * de precios. Patron de Tabs identico a /inventory/catalogs.
 */
import { createFileRoute, Link } from '@tanstack/react-router';
import { ArrowLeft, Building2, Warehouse, ShieldCheck, ListOrdered } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { BranchesManager } from '@/features/inventory-center/catalogs/BranchesManager';
import { WarehousesManager } from '@/features/inventory-center/catalogs/WarehousesManager';
import { WarrantyPoliciesManager } from '@/features/inventory-center/catalogs/WarrantyPoliciesManager';
import { PriceListsManager } from '@/features/inventory-center/catalogs/PriceListsManager';

export const Route = createFileRoute('/_authed/inventory/admin')({
  component: AdminPage,
});

function AdminPage() {
  return (
    <PageLayout
      title="Administracion"
      description="Sucursales, almacenes, politicas de garantia y listas de precios."
      actions={
        <Button variant="outline" size="sm" asChild>
          <Link
            to="/inventory"
            search={{
              search: '',
              tracking: 'all',
              stock: 'all',
              status: 'all',
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
      <Tabs defaultValue="branches">
        <TabsList>
          <TabsTrigger value="branches" className="gap-1.5">
            <Building2 className="size-3.5" /> Sucursales
          </TabsTrigger>
          <TabsTrigger value="warehouses" className="gap-1.5">
            <Warehouse className="size-3.5" /> Almacenes
          </TabsTrigger>
          <TabsTrigger value="warranties" className="gap-1.5">
            <ShieldCheck className="size-3.5" /> Garantias
          </TabsTrigger>
          <TabsTrigger value="price-lists" className="gap-1.5">
            <ListOrdered className="size-3.5" /> Listas de precios
          </TabsTrigger>
        </TabsList>

        <TabsContent value="branches" className="space-y-4">
          <BranchesManager />
        </TabsContent>

        <TabsContent value="warehouses" className="space-y-4">
          <WarehousesManager />
        </TabsContent>

        <TabsContent value="warranties" className="space-y-4">
          <WarrantyPoliciesManager />
        </TabsContent>

        <TabsContent value="price-lists" className="space-y-4">
          <PriceListsManager />
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}
