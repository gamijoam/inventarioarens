/**
 * Pagina /inventory/catalogs: gestion de catalogos (marcas, categorias, tags).
 * Tabs con TabsList + 3 managers.
 */
import { createFileRoute, Link } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { BrandsManager } from '@/features/inventory-center/catalogs/BrandsManager';
import { CategoriesManager } from '@/features/inventory-center/catalogs/CategoriesManager';
import { TagsManager } from '@/features/inventory-center/catalogs/TagsManager';

export const Route = createFileRoute('/_authed/inventory/catalogs')({
  component: CatalogsPage,
});

function CatalogsPage() {
  return (
    <PageLayout
      title="Catalogos"
      description="Gestion de marcas, categorias y tags del sistema."
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
      <Tabs defaultValue="brands">
        <TabsList>
          <TabsTrigger value="brands">Marcas</TabsTrigger>
          <TabsTrigger value="categories">Categorias</TabsTrigger>
          <TabsTrigger value="tags">Tags</TabsTrigger>
        </TabsList>

        <TabsContent value="brands" className="space-y-4">
          <BrandsManager />
        </TabsContent>

        <TabsContent value="categories" className="space-y-4">
          <CategoriesManager />
        </TabsContent>

        <TabsContent value="tags" className="space-y-4">
          <TagsManager />
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}