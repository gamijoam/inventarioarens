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
import { useNavigate } from '@tanstack/react-router';
import { Pencil, Search, ShieldCheck, UserCircle } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { Skeleton } from '@/components/ui/Skeleton';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';

import { useUsers, type UserListFilters } from './api';
import type { User, UserStatus } from './schemas';
import { StatusToggle } from './StatusToggle';

type StatusFilter = 'all' | 'active' | 'inactive';

interface UsersManagerProps {
  // Callbacks para que la ruta padre abra los dialogs.
  onCreate?: () => void;
  onEdit?: (user: User) => void;
  onChangeRoles?: (user: User) => void;
  canEdit?: boolean;
}

export function UsersManager({
  onCreate,
  onEdit,
  onChangeRoles,
  canEdit = false,
}: UsersManagerProps = {}) {
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
  const navigate = useNavigate();

  const columns = useColumns(onEdit, onChangeRoles, canEdit);
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
          {onCreate && (
            <Can I={PERMISSIONS.USERS_CREATE}>
              <Button onClick={onCreate} data-testid="users-new">
                + Nuevo usuario
              </Button>
            </Can>
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
                    <tr
                      key={row.id}
                      className="cursor-pointer border-b border-border last:border-b-0 transition-colors hover:bg-bg/40"
                      onClick={() => navigate({ to: '/users/$userId', params: { userId: String(row.original.id) } })}
                      data-testid={`users-row-${row.original.id}`}
                    >
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

function useColumns(
  onEdit?: (user: User) => void,
  onChangeRoles?: (user: User) => void,
  canEdit?: boolean,
) {
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
      // Acciones (Fase B).
      ...(canEdit
        ? [
            columnHelper.display({
              id: 'actions',
              header: '',
              cell: (info) => (
                <div className="flex items-center justify-end gap-1">
                  {onEdit && (
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      onClick={(e) => {
                        e.stopPropagation();
                        onEdit(info.row.original);
                      }}
                      title="Editar nombre"
                      aria-label="Editar nombre"
                      data-testid={`edit-user-${info.row.original.id}`}
                    >
                      <Pencil className="size-4 text-text-muted" aria-hidden="true" />
                    </Button>
                  )}
                  {onChangeRoles && (
                    <Button
                      size="icon-sm"
                      variant="ghost"
                      onClick={(e) => {
                        e.stopPropagation();
                        onChangeRoles(info.row.original);
                      }}
                      title="Cambiar roles"
                      aria-label="Cambiar roles"
                      data-testid={`change-roles-${info.row.original.id}`}
                    >
                      <ShieldCheck className="size-4 text-text-muted" aria-hidden="true" />
                    </Button>
                  )}
                  <StatusToggle user={info.row.original} canEdit={canEdit} />
                </div>
              ),
            }),
          ]
        : []),
    ],
    [columnHelper, onEdit, onChangeRoles, canEdit],
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