/**
 * RolesManager: listado de roles del tenant actual con TanStack Table.
 * Server-side pagination + filtering (search por nombre).
 *
 * Cada fila muestra:
 *   - Nombre + badge "Sistema" si es rol base
 *   - Cantidad de permisos (preview)
 *   - Acciones: editar nombre, duplicar, ver permisos, eliminar
 *
 * Roles base (is_protected) no se pueden editar (nombre) ni eliminar,
 * solo duplicar y ver permisos.
 */
import { useMemo, useState, type ChangeEvent } from 'react';
import { useNavigate } from '@tanstack/react-router';
import {
  createColumnHelper,
  flexRender,
  getCoreRowModel,
  useReactTable,
  type SortingState,
} from '@tanstack/react-table';
import { Copy, Pencil, Search, ShieldCheck, Trash2 } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent } from '@/components/ui/Card';
import { EmptyState } from '@/components/ui/EmptyState';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';

import { ConfirmDestructiveDialog } from '@/components/ConfirmDestructiveDialog';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';

import { useDeleteRole, useRoles, type Role, type RoleListFilters } from './api';
import { ProtectedRoleBadge } from './ProtectedRoleBadge';

interface RolesManagerProps {
  onCreate?: () => void;
  onEdit?: (role: Role) => void;
  onDuplicate?: (role: Role) => void;
  onEditPermissions?: (role: Role) => void;
}

export function RolesManager({
  onCreate,
  onEdit,
  onDuplicate,
  onEditPermissions,
}: RolesManagerProps = {}) {
  const navigate = useNavigate();
  const [searchInput, setSearchInput] = useState('');
  const [confirmDelete, setConfirmDelete] = useState<Role | null>(null);

  const filters = useMemo<RoleListFilters>(
    () => ({
      search: searchInput,
      page: 1,
      per_page: 25,
    }),
    [searchInput],
  );

  const { data, isLoading, isError } = useRoles(filters);
  const deleteRole = useDeleteRole();

  const columns = useColumns({ onEdit, onDuplicate, onEditPermissions, setConfirmDelete });
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
      <Card>
        <CardContent className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-[1fr_auto]">
          <div className="relative">
            <Search
              className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
              aria-hidden="true"
            />
            <Input
              value={searchInput}
              onChange={(e: ChangeEvent<HTMLInputElement>) => setSearchInput(e.target.value)}
              placeholder="Buscar rol por nombre..."
              className="pl-8"
              data-testid="roles-search"
            />
          </div>
          {onCreate && (
            <Can I={PERMISSIONS.ROLES_CREATE}>
              <Button onClick={onCreate} data-testid="roles-new">
                + Nuevo rol
              </Button>
            </Can>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="p-0">
          {isLoading && (
            <div className="space-y-2 p-4">
              {Array.from({ length: 6 }).map((_, i) => (
                <Skeleton key={i} className="h-8 w-full" />
              ))}
            </div>
          )}
          {isError && (
            <EmptyState
              title="No se pudo cargar el listado"
              description="Verifica tu conexion o tus permisos."
            />
          )}
          {data && data.data.length === 0 && (
            <EmptyState
              icon={<ShieldCheck className="size-8" />}
              title="Sin roles"
              description={
                searchInput
                  ? 'Ningun rol coincide con la busqueda.'
                  : 'Aun no hay roles en esta empresa.'
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
                      onClick={() => navigate({ to: '/access/roles/$roleId', params: { roleId: String(row.original.id) } })}
                      data-testid={`roles-row-${row.original.id}`}
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

      {data && data.meta.last_page > 1 && (
        <div className="flex items-center justify-between text-sm">
          <p className="text-text-muted">
            Mostrando {data.data.length} de {data.meta.total} roles
          </p>
          <span className="text-text-muted">
            Pagina {data.meta.current_page} / {data.meta.last_page}
          </span>
        </div>
      )}

      <ConfirmDestructiveDialog
        open={confirmDelete !== null}
        onOpenChange={(open) => !open && setConfirmDelete(null)}
        title="Eliminar rol"
        description={
          confirmDelete && (
            <>
              <p>
                Vas a eliminar el rol <strong>{confirmDelete.name}</strong>.
                Los usuarios que solo tengan este rol quedaran sin permisos.
              </p>
              <p className="mt-2">Esta accion no se puede deshacer.</p>
            </>
          )
        }
        confirmText={confirmDelete?.name ?? ''}
        confirmLabel="Eliminar rol"
        onConfirm={async () => {
          if (!confirmDelete) return;
          try {
            await deleteRole.mutateAsync(confirmDelete.id);
            void navigate({ to: '/access/roles', search: { search: searchInput, page: 1 } as never });
          } catch (err) {
            const msg = err instanceof Error ? err.message : 'Error al eliminar el rol.';
            alert(msg); // fallback porque toast ya se mostro en el componente
          }
        }}
        loading={deleteRole.isPending}
        dangerLevel="high"
      />
    </div>
  );
}

function useColumns(opts: {
  onEdit?: (role: Role) => void;
  onDuplicate?: (role: Role) => void;
  onEditPermissions?: (role: Role) => void;
  setConfirmDelete: (role: Role | null) => void;
}) {
  const columnHelper = createColumnHelper<Role>();
  return useMemo(
    () => [
      columnHelper.accessor('name', {
        header: 'Nombre',
        cell: (info) => {
          const r = info.row.original;
          return (
            <div className="flex items-center gap-2">
              <span className="font-medium text-text-primary">{info.getValue()}</span>
              {r.is_protected && <ProtectedRoleBadge isProtected />}
            </div>
          );
        },
      }),
      columnHelper.accessor('permissions', {
        header: 'Permisos',
        enableSorting: false,
        cell: (info) => {
          const perms = info.getValue();
          return perms && perms.length > 0 ? (
            <Badge variant="default" className="text-[10px]">
              {perms.length}
            </Badge>
          ) : (
            <span className="text-xs text-text-muted">Sin permisos</span>
          );
        },
      }),
      columnHelper.display({
        id: 'actions',
        header: '',
        cell: (info) => {
          const r = info.row.original;
          return (
            <div className="flex items-center justify-end gap-1">
              {!r.is_protected && opts.onEdit && (
                <Can I={PERMISSIONS.ROLES_UPDATE}>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={() => opts.onEdit?.(r)}
                    title="Editar nombre"
                    aria-label="Editar nombre"
                    data-testid={`role-edit-${r.id}`}
                  >
                    <Pencil className="size-4 text-text-muted" aria-hidden="true" />
                  </Button>
                </Can>
              )}
              {opts.onEditPermissions && (
                <Can I={PERMISSIONS.ROLES_UPDATE}>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={() => opts.onEditPermissions?.(r)}
                    title="Ver / editar permisos"
                    aria-label="Ver / editar permisos"
                    data-testid={`role-permissions-${r.id}`}
                  >
                    <ShieldCheck className="size-4 text-text-muted" aria-hidden="true" />
                  </Button>
                </Can>
              )}
              {opts.onDuplicate && (
                <Can I={PERMISSIONS.ROLES_CREATE}>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={() => opts.onDuplicate?.(r)}
                    title="Duplicar rol"
                    aria-label="Duplicar rol"
                    data-testid={`role-duplicate-${r.id}`}
                  >
                    <Copy className="size-4 text-text-muted" aria-hidden="true" />
                  </Button>
                </Can>
              )}
              {!r.is_protected && (
                <Can I={PERMISSIONS.ROLES_DELETE}>
                  <Button
                    size="icon-sm"
                    variant="ghost"
                    onClick={() => opts.setConfirmDelete(r)}
                    title="Eliminar rol"
                    aria-label="Eliminar rol"
                    data-testid={`role-delete-${r.id}`}
                  >
                    <Trash2 className="size-4 text-danger" aria-hidden="true" />
                  </Button>
                </Can>
              )}
            </div>
          );
        },
      }),
    ],
    [columnHelper, opts],
  );
}