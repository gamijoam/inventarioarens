/**
 * Listado de productos del Centro de Inventario.
 * Tabla densa con TanStack Table + filtros + paginacion server-side.
 */
import { useMemo, useState, type ChangeEvent } from 'react';
import { Link, useNavigate, createFileRoute } from '@tanstack/react-router';
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
  type SortingState,
} from '@tanstack/react-table';
import { Download, Eye, Package, Search } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';
import { Card, CardContent } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { EmptyState } from '@/components/ui/EmptyState';
import { Skeleton } from '@/components/ui/Skeleton';
import { Checkbox } from '@/components/ui/Checkbox';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { formatMoney } from '@/lib/money';
import { cn } from '@/lib/cn';

import { useProducts } from '@/features/inventory-center/api';
import { CreateProductDialog } from '@/features/inventory-center/dialogs/CreateProductDialog';
import { BulkActionsMenu } from '@/features/inventory-center/bulk-actions/BulkActionsMenu';
import { useExportProducts } from '@/features/inventory-center/useExportProducts';
import type { Product } from '@/features/inventory-center/schemas';

type TrackingFilter = 'all' | 'quantity' | 'serialized';
type StockFilter = 'all' | 'available' | 'low' | 'critical' | 'out' | 'overstock';
type StatusFilter = 'all' | 'active' | 'inactive';

interface InventorySearch {
  search: string;
  tracking: TrackingFilter;
  stock: StockFilter;
  status: StatusFilter;
  page: number;
  brand_id: number | undefined;
  category_id: number | undefined;
  tag_id: number | undefined;
  low_stock_threshold: number | undefined;
  sort_by: string | undefined;
  sort_dir: 'asc' | 'desc' | undefined;
}

export const Route = createFileRoute('/_authed/inventory/')({
  component: InventoryListPage,
  validateSearch: (search: Record<string, unknown>): InventorySearch => ({
    search: typeof search.search === 'string' ? search.search : '',
    tracking: ['all', 'quantity', 'serialized'].includes(search.tracking as string)
      ? (search.tracking as TrackingFilter)
      : 'all',
    stock: ['all', 'available', 'low', 'critical', 'out', 'overstock'].includes(
      search.stock as string,
    )
      ? (search.stock as StockFilter)
      : 'all',
    status: ['all', 'active', 'inactive'].includes(search.status as string)
      ? (search.status as StatusFilter)
      : 'all',
    page: typeof search.page === 'number' ? search.page : 1,
    brand_id:
      typeof search.brand_id === 'number'
        ? (search.brand_id)
        : typeof search.brand_id === 'string'
          ? Number(search.brand_id) || undefined
          : undefined,
    category_id:
      typeof search.category_id === 'number'
        ? (search.category_id)
        : typeof search.category_id === 'string'
          ? Number(search.category_id) || undefined
          : undefined,
    tag_id:
      typeof search.tag_id === 'number'
        ? (search.tag_id)
        : typeof search.tag_id === 'string'
          ? Number(search.tag_id) || undefined
          : undefined,
    low_stock_threshold:
      typeof search.low_stock_threshold === 'number'
        ? (search.low_stock_threshold)
        : typeof search.low_stock_threshold === 'string'
          ? Number(search.low_stock_threshold) || undefined
          : undefined,
    sort_by: typeof search.sort_by === 'string' ? (search.sort_by) : undefined,
    sort_dir:
      search.sort_dir === 'asc' || search.sort_dir === 'desc'
        ? (search.sort_dir)
        : undefined,
  }),
});

function InventoryListPage() {
  const search: InventorySearch = Route.useSearch();
  const navigate = useNavigate({ from: Route.fullPath });

  const [searchInput, setSearchInput] = useState(search.search);
  const [createOpen, setCreateOpen] = useState(false);
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

  const filters = useMemo(
    () => ({
      search: search.search,
      tracking_type: search.tracking,
      stock_status: search.stock,
      active_status: search.status,
      page: search.page,
      per_page: 25,
    }),
    [search.search, search.tracking, search.stock, search.status, search.page],
  );

  const { data, isLoading, isError } = useProducts(filters);
  const exportProducts = useExportProducts();

  const updateSearch = (patch: Partial<InventorySearch>) => {
    void navigate({ search: { ...search, ...patch, page: 1 } });
  };

  const goToPage = (page: number) => {
    void navigate({ search: { ...search, page } });
  };

  const columns = useColumns(selectedIds, setSelectedIds, data?.data ?? []);

  const table = useReactTable({
    data: data?.data ?? [],
    columns,
    state: { sorting: [] as SortingState },
    getCoreRowModel: getCoreRowModel(),
    manualPagination: true,
    manualFiltering: true,
    manualSorting: true,
    pageCount: data?.meta.last_page ?? 0,
  });

  return (
    <PageLayout
      title="Centro de Inventario"
      description="Listado de productos con stock, precios y estado."
      actions={
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            leftIcon={<Download className="size-4" />}
            onClick={() => exportProducts.exportCsv(filters)}
            loading={exportProducts.isExporting}
            data-testid="export-csv"
          >
            Exportar CSV
          </Button>
          <Can I={PERMISSIONS.PRODUCTS_CREATE}>
            <Button onClick={() => setCreateOpen(true)} data-testid="new-product">
            + Nuevo producto
          </Button>
          </Can>
        </div>
      }
    >
      <BulkActionsMenu
        selectedIds={Array.from(selectedIds)}
        onClearSelection={() => setSelectedIds(new Set())}
        onSuccess={() => setSelectedIds(new Set())}
      />

      {/* Filtros */}
      <Card>
        <CardContent className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
          <div className="relative lg:col-span-2">
            <Search
              className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
              aria-hidden="true"
            />
            <Input
              value={searchInput}
              onChange={(e: ChangeEvent<HTMLInputElement>) => setSearchInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') updateSearch({ search: searchInput });
              }}
              placeholder="Buscar por SKU o nombre..."
              className="pl-8"
              data-testid="inventory-search"
            />
          </div>
          <select
            className={selectClass}
            value={search.tracking}
            onChange={(e: ChangeEvent<HTMLSelectElement>) =>
              updateSearch({ tracking: e.target.value as TrackingFilter })
            }
            data-testid="inventory-tracking"
          >
            <option value="all">Todos los tipos</option>
            <option value="quantity">Por cantidad</option>
            <option value="serialized">Serializados</option>
          </select>
          <select
            className={selectClass}
            value={search.stock}
            onChange={(e: ChangeEvent<HTMLSelectElement>) =>
              updateSearch({ stock: e.target.value as StockFilter })
            }
          >
            <option value="all">Todos los stocks</option>
            <option value="available">Con stock</option>
            <option value="low">Bajo stock</option>
            <option value="critical">Critico</option>
            <option value="out">Sin stock</option>
            <option value="overstock">Sobre stock</option>
          </select>
          <select
            className={selectClass}
            value={search.status}
            onChange={(e: ChangeEvent<HTMLSelectElement>) =>
              updateSearch({ status: e.target.value as StatusFilter })
            }
          >
            <option value="all">Todos los estados</option>
            <option value="active">Activos</option>
            <option value="inactive">Inactivos</option>
          </select>
        </CardContent>
      </Card>

      {/* Tabla */}
      <Card>
        <CardContent className="p-0">
          {isLoading && <TableSkeleton />}
          {isError && (
            <EmptyState
              title="No se pudo cargar el inventario"
              description="Verifica tu conexión o tus permisos."
            />
          )}
          {data && data.data.length === 0 && (
            <EmptyState
              icon={<Package className="size-8" />}
              title="Sin productos"
              description="No hay productos que coincidan con los filtros aplicados."
            />
          )}
          {data && data.data.length > 0 && (
            <div className="overflow-x-auto">
              <table className="w-full table-dense">
                <thead className="border-b border-border bg-bg/60 text-left">
                  {table.getHeaderGroups().map((hg) => (
                    <tr key={hg.id}>
                      {hg.headers.map((header) => (
                        <th
                          key={header.id}
                          className="px-3 py-2 font-semibold uppercase tracking-wide text-text-secondary"
                        >
                          {header.isPlaceholder
                            ? null
                            : flexRender(header.column.columnDef.header, header.getContext())}
                        </th>
                      ))}
                    </tr>
                  ))}
                </thead>
                <tbody>
                  {table.getRowModel().rows.map((row) => (
                    <tr key={row.id} className="border-b border-border last:border-b-0 hover:bg-bg/50">
                      {row.getVisibleCells().map((cell) => (
                        <td key={cell.id} className="px-3 py-2">
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Paginacion */}
      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <p className="text-text-muted">
            Mostrando {data.data.length} de {data.meta.total} productos
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={filters.page <= 1}
              onClick={() => goToPage(Math.max(1, filters.page - 1))}
            >
              Anterior
            </Button>
            <span className="flex items-center px-2 text-text-muted">
              Pagina {data.meta.current_page} / {data.meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={filters.page >= data.meta.last_page}
              onClick={() => goToPage(filters.page + 1)}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}

      <CreateProductDialog open={createOpen} onOpenChange={setCreateOpen} />
    </PageLayout>
  );
}

const selectClass = cn(
  'flex h-9 w-full rounded border border-border-strong bg-surface px-3 text-sm shadow-sm',
  'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1',
);

function useColumns(
  selectedIds: Set<number>,
  setSelectedIds: React.Dispatch<React.SetStateAction<Set<number>>>,
  data: Product[],
) {
  const columnHelper = createColumnHelper<Product>();

  return useMemo(
    () => [
      columnHelper.display({
        id: 'select',
        header: () => (
          <Checkbox
            checked={selectedIds.size > 0 && selectedIds.size === data.length}
            onCheckedChange={(checked) => {
              if (checked) {
                setSelectedIds(new Set(data.map((p: Product) => p.id)));
              } else {
                setSelectedIds(new Set());
              }
            }}
            aria-label="Seleccionar todo"
          />
        ),
        cell: (info) => (
          <Checkbox
            checked={selectedIds.has(info.row.original.id)}
            onCheckedChange={(checked) => {
              setSelectedIds((prev) => {
                const next = new Set(prev);
                if (checked) next.add(info.row.original.id);
                else next.delete(info.row.original.id);
                return next;
              });
            }}
            aria-label={`Seleccionar ${info.row.original.name}`}
            data-testid={`select-${info.row.original.id}`}
          />
        ),
      }),
      columnHelper.accessor('sku', {
        header: 'SKU',
        cell: (info) => (
          <code className="rounded bg-bg px-1.5 py-0.5 text-xs">{info.getValue()}</code>
        ),
      }),
      columnHelper.accessor('name', {
        header: 'Nombre',
        cell: (info) => (
          <span className="font-medium text-text-primary">{info.getValue()}</span>
        ),
      }),
      columnHelper.accessor('tracking_type', {
        header: 'Tipo',
        cell: (info) => {
          const t = info.getValue();
          return (
            <Badge variant={t === 'serialized' ? 'info' : 'default'}>
              {t === 'serialized' ? 'Serializado' : 'Cantidad'}
            </Badge>
          );
        },
      }),
      columnHelper.accessor(
        (row) => {
          const v = (row as { available?: string | number | null }).available;
          if (v == null) return '—';
          const n = typeof v === 'string' ? parseFloat(v) : v;
          return Number.isNaN(n) ? '—' : String(n);
        },
        {
          id: 'stock',
          header: 'Stock',
          cell: (info) => <span className="tabular-nums">{String(info.getValue())}</span>,
        },
      ),
      columnHelper.accessor('base_price', {
        header: 'Precio base',
        cell: (info) => formatMoney(info.getValue()),
      }),
      columnHelper.accessor('is_active', {
        header: 'Estado',
        cell: (info) => (
          <Badge variant={info.getValue() ? 'success' : 'default'}>
            {info.getValue() ? 'Activo' : 'Inactivo'}
          </Badge>
        ),
      }),
      columnHelper.display({
        id: 'actions',
        header: '',
        cell: (info) => (
          <Link
            to="/inventory/$productId"
            params={{ productId: String(info.row.original.id) }}
            className="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
            data-testid={`inventory-view-${info.row.original.id}`}
          >
            <Eye className="size-3.5" aria-hidden="true" />
            Ver
          </Link>
        ),
      }),
    ],
    // selectedIds y setSelectedIds se incluyen como deps intencionalmente:
    // cambian cuando el user selecciona/deselecciona filas y la tabla
    // debe re-renderizar los checkboxes de cada fila.
    [columnHelper, selectedIds, setSelectedIds, data],
  );
}

function TableSkeleton() {
  return (
    <div className="space-y-2 p-4">
      {Array.from({ length: 6 }).map((_, i) => (
        <Skeleton key={i} className="h-8 w-full" />
      ))}
    </div>
  );
}







