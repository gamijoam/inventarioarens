/**
 * UsersManager: listado de usuarios del tenant actual con TanStack Table.
 *
 * Fase A: solo lectura (sin acciones de crear/editar/cambiar roles). Esas
 * se implementan en Fase B (CreateUserDialog, EditUserDialog, ChangeRolesDialog,
 * StatusToggle).
 *
 * Server-side pagination + filtering: el backend pagina con ?page y ?per_page,
 * y filtra con ?search, ?role_id, ?status.
 */
import { useMemo, useState, type ChangeEvent } from 'react';
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
  type SortingState,
} from '@tanstack/react-table';
import { Search, UserCircle } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';

import { useUsers, type UserListFilters } from './api';
import type { User, UserStatus } from './schemas';

type StatusFilter = 'all' | 'active' | 'inactive';

interface UsersManagerProps {
  // Slot para que la ruta padre monte dialogs de Fase B sin reescribir el manager.
  // Por ahora solo se usa el listado.
  onCreate?: () => void;
  canCreate?: boolean;
}

export function UsersManager({ onCreate, canCreate = false }: UsersManagerProps = {}) {
  const [searchInput, setSearchInput] = useState('');
  const [status, setStatus] = useState<StatusFilter>('all');

  const filters = useMemo<UserListFilters>(
    () => ({
      search: searchInput,
      status,
      page: 1,
      per_page: 25,
    }),
    [searchInput, status],
  );

  const { data, isLoading, isError } = useUsers(filters);

  const columns = useColumns();
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
    <div className="space-y-3">
      {/* Filtros */}
      <Card>
        <CardContent className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-[1fr_220px_auto]">
          <div className="relative">
            <Search
              className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
              aria-hidden="true"
            />
            <Input
              value={searchInput}
              onChange={(e: ChangeEvent<HTMLInputElement>) => setSearchInput(e.target.value)}
              placeholder="Buscar por nombre o email..."
              className="pl-8"
              data-testid="users-search"
            />
          </div>
          <Select
            value={status}
            onChange={(e: ChangeEvent<HTMLSelectElement>) =>
              setStatus(e.target.value as StatusFilter)
            }
            data-testid="users-status-filter"
          >
            <option value="all">Todos los estados</option>
            <option value="active">Activos</option>
            <option value="inactive">Inactivos</option>
          </Select>
          {canCreate && onCreate && (
            <Button onClick={onCreate} data-testid="users-new">
              + Nuevo usuario
            </Button>
          )}
        </CardContent>
      </Card>

      {/* Tabla */}
      <Card>
        <CardContent className="p-0">
          {isLoading && <TableSkeleton />}
          {isError && (
            <EmptyState
              title="No se pudo cargar el listado"
              description="Verifica tu conexion o tus permisos."
            />
          )}
          {data && data.data.length === 0 && (
            <EmptyState
              icon={<UserCircle className="size-8" />}
              title="Sin usuarios"
              description={
                searchInput || status !== 'all'
                  ? 'Ningun usuario coincide con los filtros.'
                  : 'Aun no hay usuarios en esta empresa.'
              }
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
                    <tr key={row.id} className="border-b border-border last:border-b-0">
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
            Mostrando {data.data.length} de {data.meta.total} usuarios
          </p>
          <div className="flex gap-2">
            <Button
              variant="outline"
              size="sm"
              disabled={filters.page === undefined || filters.page <= 1}
            >
              Anterior
            </Button>
            <span className="flex items-center px-2 text-text-muted">
              Pagina {data.meta.current_page} / {data.meta.last_page}
            </span>
            <Button
              variant="outline"
              size="sm"
              disabled={filters.page !== undefined && filters.page >= data.meta.last_page}
            >
              Siguiente
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

function useColumns() {
  const columnHelper = createColumnHelper<User>();
  return useMemo(
    () => [
      columnHelper.accessor('name', {
        header: 'Nombre',
        cell: (info) => (
          <div className="flex items-center gap-2">
            <UserCircle className="size-4 shrink-0 text-text-muted" aria-hidden="true" />
            <span className="font-medium text-text-primary">{info.getValue()}</span>
          </div>
        ),
      }),
      columnHelper.accessor('email', {
        header: 'Email',
        cell: (info) => <span className="text-text-muted">{info.getValue()}</span>,
      }),
      columnHelper.accessor('roles', {
        header: 'Roles',
        enableSorting: false,
        cell: (info) => {
          const roles = info.getValue();
          if (roles.length === 0) {
            return <span className="text-xs text-text-muted">Sin rol</span>;
          }
          return (
            <div className="flex flex-wrap gap-1">
              {roles.map((r) => (
                <Badge key={r.id} variant="info" className="text-[10px]">
                  {r.name}
                </Badge>
              ))}
            </div>
          );
        },
      }),
      columnHelper.accessor('status', {
        header: 'Estado',
        cell: (info) => <StatusBadge status={info.getValue()} />,
      }),
      columnHelper.accessor('created_at', {
        header: 'Creado',
        cell: (info) => {
          const v = info.getValue();
          if (!v) return <span className="text-text-muted">-</span>;
          return <span className="text-xs text-text-muted">{new Date(v).toLocaleDateString()}</span>;
        },
      }),
    ],
    [columnHelper],
  );
}

function StatusBadge({ status }: { status: UserStatus }) {
  return (
    <Badge variant={status === 'active' ? 'success' : 'default'} className="text-[10px]">
      {status === 'active' ? 'Activo' : 'Inactivo'}
    </Badge>
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