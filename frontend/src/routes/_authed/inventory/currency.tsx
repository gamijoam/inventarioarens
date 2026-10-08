/**
 * Pagina /inventory/currency: gestion de tipos de tasa y su historial.
 */
import { createFileRoute, Link } from '@tanstack/react-router';
import { ArrowLeft, TrendingUp } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Button } from '@/components/ui/Button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { ExchangeRateTypesManager } from '@/features/inventory-center/catalogs/ExchangeRateTypesManager';
import { ExchangeRatesManager } from '@/features/inventory-center/catalogs/ExchangeRatesManager';

export const Route = createFileRoute('/_authed/inventory/currency')({
  component: CurrencyPage,
});

function CurrencyPage() {
  return (
    <PageLayout
      title="Tasas de cambio"
      description="Actualiza las tasas de cambio y consulta su historial."
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
      <Tabs defaultValue="rates">
        <TabsList>
          <TabsTrigger value="types">
            <TrendingUp className="size-3.5" aria-hidden="true" />
            Tipos de tasa
          </TabsTrigger>
          <TabsTrigger value="rates">
            <TrendingUp className="size-3.5" aria-hidden="true" />
            Tasas vigentes e historial
          </TabsTrigger>
        </TabsList>

        <TabsContent value="types" className="space-y-4">
          <ExchangeRateTypesManager />
        </TabsContent>

        <TabsContent value="rates" className="space-y-4">
          <ExchangeRatesManager />
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}
