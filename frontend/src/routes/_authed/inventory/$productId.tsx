/**
 * Detalle de producto con tabs: General, Stock, Seriales, Precios, Movimientos.
 * Carga perezosa de cada tab.
 */
import { useState } from 'react';
import { Link, useNavigate, createFileRoute } from '@tanstack/react-router';
import { ArrowLeft, Edit, Trash2 } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/Card';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { Spinner } from '@/components/ui/Spinner';
import { Skeleton } from '@/components/ui/Skeleton';
import { EmptyState } from '@/components/ui/EmptyState';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/Tabs';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { formatCost, formatMoney } from '@/lib/money';
import { formatRelative } from '@/lib/format';
import { cn } from '@/lib/cn';

import { useProduct, useProductSerials, useProductStockByWarehouse, useProductMovements } from '@/features/inventory-center/api';
import { PricesEditor } from '@/features/inventory-center/components/PricesEditor';
import { ProductRelations } from '@/features/inventory-center/components/ProductRelations';
import { WacDisplay } from '@/features/inventory-center/components/WacDisplay';
import { KardexTab } from '@/features/inventory-center/components/KardexTab';
import { AuditsTab } from '@/features/inventory-center/components/AuditsTab';
import { EditProductDialog } from '@/features/inventory-center/dialogs/EditProductDialog';
import { DeleteProductDialog } from '@/features/inventory-center/dialogs/DeleteProductDialog';
import type { ProductStock, ProductSerial, ProductMovement } from '@/features/inventory-center/schemas';

export const Route = createFileRoute('/_authed/inventory/$productId')({
  component: ProductDetailPage,
});

function ProductDetailPage() {
  const { productId } = Route.useParams();
  const id = parseInt(productId, 10);
  const navigate = useNavigate();
  const [activeTab, setActiveTab] = useState('general');
  const [editOpen, setEditOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);

  // Hooks (siempre antes de cualquier return condicional).
  const { data: product, isLoading, isError } = useProduct(id);
  const { data: stock_by_warehouse = [] } = useProductStockByWarehouse(id);
  const { data: serialsData = [] } = useProductSerials(id);
  const { data: recent_movements = [] } = useProductMovements(id);
  const serials: ProductSerial[] = serialsData;

  if (isLoading) {
    return (
      <PageLayout title="Cargando producto...">
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-48 w-full" />
      </PageLayout>
    );
  }

  if (isError || !product) {
    return (
      <PageLayout title="Producto no encontrado">
        <EmptyState
          title="No se encontró el producto"
          description="El producto puede haber sido eliminado o no tienes permiso para verlo."
          action={
            <Button
              variant="outline"
              onClick={() =>
                navigate({
                  to: '/inventory',
                  search: {
                    search: '',
                    tracking: 'all',
                    stock: 'all',
                    status: 'all',
                    page: 1,
                    brand_id: undefined,
                    category_id: undefined,
                    tag_id: undefined,
                    low_stock_threshold: undefined,
                    sort_by: undefined,
                    sort_dir: undefined,
                  },
                })
              }
            >
              <ArrowLeft className="size-4" aria-hidden="true" />
              Volver al inventario
            </Button>
          }
        />
      </PageLayout>
    );
  }

  return (
    <PageLayout
      title={product.name}
      description={`SKU ${product.sku} · ${product.tracking_type === 'serialized' ? 'Serializado' : 'Por cantidad'}`}
      breadcrumb={
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
            low_stock_threshold: undefined,
            sort_by: undefined,
            sort_dir: undefined,
          }}
          className="inline-flex items-center gap-1 text-xs text-text-muted hover:text-primary"
        >
          <ArrowLeft className="size-3" aria-hidden="true" />
          Inventario
        </Link>
      }
      actions={
        <div className="flex items-center gap-2">
          <Can I={PERMISSIONS.PRODUCTS_UPDATE}>
            <Button
              variant="outline"
              leftIcon={<Edit className="size-4" />}
              onClick={() => setEditOpen(true)}
              data-testid="edit-product"
            >
              Editar
            </Button>
          </Can>
          <Can I={PERMISSIONS.PRODUCTS_DELETE}>
            <Button
              variant="danger"
              leftIcon={<Trash2 className="size-4" />}
              onClick={() => setDeleteOpen(true)}
              data-testid="delete-product"
            >
              Eliminar
            </Button>
          </Can>
        </div>
      }
    >
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="stock">Stock</TabsTrigger>
          {product.tracking_type === 'serialized' && (
            <TabsTrigger value="serials">Seriales / IMEI</TabsTrigger>
          )}
          <TabsTrigger value="prices">Precios por lista</TabsTrigger>
          <TabsTrigger value="movements">Movimientos</TabsTrigger>
          <TabsTrigger value="kardex">Kardex</TabsTrigger>
          <TabsTrigger value="audits">Auditoria</TabsTrigger>
        </TabsList>

        <TabsContent value="general" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Información general</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="SKU"><code className="rounded bg-bg px-1.5 py-0.5 text-xs">{product.sku}</code></Field>
              <Field label="Nombre">{product.name}</Field>
              <Field label="Tipo">
                <Badge variant={product.tracking_type === 'serialized' ? 'info' : 'default'}>
                  {product.tracking_type === 'serialized' ? 'Serializado (IMEI/serial)' : 'Por cantidad'}
                </Badge>
              </Field>
              <Field label="Estado">
                <Badge variant={product.is_active ? 'success' : 'default'}>
                  {product.is_active ? 'Activo' : 'Inactivo'}
                </Badge>
              </Field>
              <Field label="Precio base">{formatMoney(product.base_price)}</Field>
              <Field label="Moneda de venta preferida">{product.sale_currency ?? '—'}</Field>
              <Field label="Vendible">
                <Badge variant={product.is_active ? 'success' : 'default'}>
                  {product.is_active ? 'Sí' : 'No'}
                </Badge>
              </Field>
              <Field label="Tiene garantía">
                <Badge variant={product.warranty_policy_id ? 'info' : 'default'}>
                  {product.warranty_policy_id ? 'Sí' : 'No'}
                </Badge>
              </Field>
              <Field label="Última actualización">
                {product.updated_at ? formatRelative(product.updated_at) : '—'}
              </Field>
            </CardContent>
          </Card>

          <ProductRelations product={product} />
          <WacDisplay product={product} />
        </TabsContent>

        <TabsContent value="stock" className="space-y-4">
          <StockTab productId={id} initialStock={stock_by_warehouse} />
        </TabsContent>

        {product.tracking_type === 'serialized' && (
          <TabsContent value="serials" className="space-y-4">
            <SerialsTab productId={id} initialSerials={serials} />
          </TabsContent>
        )}

        <TabsContent value="prices" className="space-y-4">
          <PricesTab productId={id} />
        </TabsContent>

        <TabsContent value="movements" className="space-y-4">
          <MovementsTab movements={recent_movements} />
        </TabsContent>

        <TabsContent value="kardex" className="space-y-4">
          <KardexTab productId={id} />
        </TabsContent>

        <TabsContent value="audits" className="space-y-4">
          <AuditsTab productId={id} />
        </TabsContent>
      </Tabs>

      {product && (
        <EditProductDialog
          product={product}
          open={editOpen}
          onOpenChange={setEditOpen}
        />
      )}

      {product && (
        <DeleteProductDialog
          productId={product.id}
          productName={product.name}
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
        />
      )}
    </PageLayout>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-text-muted">{label}</dt>
      <dd className="mt-1 text-sm">{children}</dd>
    </div>
  );
}

function StockTab({
  initialStock,
}: {
  productId: number;
  initialStock: ProductStock[];
}) {
  if (initialStock.length === 0) {
    return (
      <EmptyState
        title="Sin stock registrado"
        description="Este producto aún no tiene stock en ningún almacén."
      />
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Stock por almacén</CardTitle>
        <CardDescription>Distribucion actual del producto.</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">
                Almacén
              </th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                Disponible
              </th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                Reservado
              </th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">
                Dañado
              </th>
            </tr>
          </thead>
          <tbody>
            {initialStock.map((s) => {
              const qty = typeof s.quantity === 'string' ? parseFloat(s.quantity) : s.quantity;
              const res = s.reserved != null ? (typeof s.reserved === 'string' ? parseFloat(s.reserved) : s.reserved) : null;
              const dmg = s.damaged != null ? (typeof s.damaged === 'string' ? parseFloat(s.damaged) : s.damaged) : null;
              return (
                <tr key={s.warehouse_id} className="border-b border-border last:border-b-0">
                  <td className="px-3 py-2">
                    <div className="font-medium">{s.warehouse_name}</div>
                    <div className="text-xs text-text-muted">{s.warehouse_code}</div>
                  </td>
                  <td className="px-3 py-2 text-right tabular-nums">{qty}</td>
                  <td className="px-3 py-2 text-right tabular-nums text-text-muted">{res ?? 0}</td>
                  <td className="px-3 py-2 text-right tabular-nums text-text-muted">{dmg ?? 0}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}

function SerialsTab({
  productId,
  initialSerials,
}: {
  productId: number;
  initialSerials: ProductSerial[];
}) {
  const { data: serialList = [], isLoading } = useProductSerials(productId);
  const serials: ProductSerial[] = serialList.length > 0 ? serialList : initialSerials;
  if (isLoading) return <Spinner label="Cargando seriales..." />;
  if (serials.length === 0) {
    return (
      <EmptyState
        title="Sin seriales registrados"
        description="Este producto serializado aún no tiene IMEI/serial asignados."
      />
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Seriales / IMEI</CardTitle>
        <CardDescription>{serials.length} unidades físicas.</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Serial</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Tipo</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Estado</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Almacén</th>
            </tr>
          </thead>
          <tbody>
            {serials.map((s: ProductSerial) => (
              <tr key={s.id} className="border-b border-border last:border-b-0">
                <td className="px-3 py-2 font-mono text-xs">{s.serial_number}</td>
                <td className="px-3 py-2 text-text-muted">{s.serial_type}</td>
                <td className="px-3 py-2">
                  <Badge
                    variant={
                      s.status === 'available'
                        ? 'success'
                        : s.status === 'sold'
                          ? 'default'
                          : s.status === 'damaged'
                            ? 'danger'
                            : 'warning'
                    }
                  >
                    {s.status}
                  </Badge>
                </td>
                <td className="px-3 py-2 text-text-muted">{s.warehouse_name ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}

function PricesTab({ productId }: { productId: number }) {
  return <PricesEditor productId={productId} />;
}

function MovementsTab({ movements }: { movements: ProductMovement[] }) {
  if (movements.length === 0) {
    return (
      <EmptyState
        title="Sin movimientos"
        description="Este producto aún no tiene movimientos de stock."
      />
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Movimientos recientes</CardTitle>
        <CardDescription>Últimos {movements.length} movimientos de stock.</CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <table className="w-full table-dense">
          <thead className="border-b border-border bg-bg/60 text-left">
            <tr>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Fecha</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Tipo</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Almacén</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Cantidad</th>
              <th className="px-3 py-2 text-right font-semibold uppercase tracking-wide text-text-secondary">Costo unit.</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Referencia</th>
              <th className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary">Usuario</th>
            </tr>
          </thead>
          <tbody>
            {movements.map((m) => (
              <tr key={m.id} className="border-b border-border last:border-b-0">
                <td className="px-3 py-2 text-text-muted">{formatRelative(m.created_at)}</td>
                <td className="px-3 py-2">
                  <Badge variant={m.type.startsWith('in') ? 'success' : m.type.startsWith('out') ? 'warning' : 'default'}>
                    {m.type}
                  </Badge>
                </td>
                <td className="px-3 py-2 text-text-muted">{m.warehouse_name ?? '—'}</td>
                <td className="px-3 py-2 text-right tabular-nums">{m.quantity}</td>
                <td className={cn('px-3 py-2 text-right tabular-nums', m.unit_cost == null && 'text-text-muted')}>
                  {formatCost(m.unit_cost)}
                </td>
                <td className="px-3 py-2 text-xs text-text-muted">{m.reference ?? '—'}</td>
                <td className="px-3 py-2 text-text-muted">{m.user_name ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </CardContent>
    </Card>
  );
}

