/**
 * GroupsTree: lista jerarquica de Tenant Groups y sus spinoffs/usuarios.
 *
 * Cada grupo es un card con:
 *  - Header: nombre, slug, plan, contadores
 *  - Lista de spinoffs (empresas hijas) cargados lazy
 *  - Lista de usuarios de la organizacion (grupo + spinoffs) lazy
 *  - Resumen operativo de roles, estados y alcance para Owners/Admins
 *  - Botones "Agregar empresa" y "Agregar usuario" que abren dialogs
 *
 * Acciones disponibles:
 *  - Crear grupo (en el padre de la lista)
 *  - Expandir/colapsar la lista
 *  - Refrescar lista de grupos
 */
import { useState } from 'react';
import {
  Boxes,
  Building2,
  ChevronDown,
  ChevronRight,
  CreditCard,
  ExternalLink,
  Loader2,
  Package,
  Plus,
  RefreshCw,
  ShieldCheck,
  Tag,
  TrendingUp,
  UserPlus,
  Users,
} from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { EmptyState } from '@/components/ui/EmptyState';
import { cn } from '@/lib/cn';

import {
  useTenantGroups,
  useGroupSpinoffs,
  useGroupUsers,
  useGroupSharedCatalog,
  type TenantGroup,
  type TenantSpinoff,
  type GroupUser,
} from './tenantGroupsApi';
import { CreateSpinoffDialog } from './CreateSpinoffDialog';
import { CreateGroupUserDialog } from './CreateGroupUserDialog';

interface GroupsTreeProps {
  onCreateGroup: () => void;
}

export function GroupsTree({ onCreateGroup }: GroupsTreeProps) {
  const { data: groups = [], isLoading, isError, error, refetch, isFetching } = useTenantGroups();
  const [expanded, setExpanded] = useState<Record<number, boolean>>({});

  function toggle(id: number) {
    setExpanded((prev) => ({ ...prev, [id]: !prev[id] }));
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-base font-semibold">Mis organizaciones</h2>
          <p className="text-xs text-text-muted">
            Cada grupo contiene una o mas empresas. Como Owner, puedes agregar empresas hijas
            y administrarlas todas desde aqui.
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            onClick={() => refetch()}
            disabled={isFetching}
            data-testid="groups-refresh"
          >
            {isFetching ? (
              <Loader2 className="size-3.5 animate-spin" />
            ) : (
              <RefreshCw className="size-3.5" />
            )}
            Actualizar
          </Button>
          <Button size="sm" onClick={onCreateGroup} data-testid="groups-create">
            <Plus className="size-3.5" /> Crear organizacion
          </Button>
        </div>
      </div>

      {isLoading ? (
        <Spinner label="Cargando organizaciones..." />
      ) : isError ? (
        <Card>
          <CardContent className="py-6 text-center text-sm text-danger">
            Error al cargar: {error?.message ?? 'desconocido'}
          </CardContent>
        </Card>
      ) : groups.length === 0 ? (
        <EmptyState
          title="Aun no tienes organizaciones"
          description="Crea tu primera organizacion (grupo + empresa inicial) para empezar a operar."
          action={
            <Button onClick={onCreateGroup}>
              <Plus className="size-3.5" /> Crear primera organizacion
            </Button>
          }
        />
      ) : (
        <div className="space-y-3">
          {groups.map((g) => (
            <GroupCard
              key={g.id}
              group={g}
              isExpanded={!!expanded[g.id]}
              onToggle={() => toggle(g.id)}
              onCreated={() => {
                void refetch();
              }}
            />
          ))}
        </div>
      )}
    </div>
  );
}

interface GroupCardProps {
  group: TenantGroup;
  isExpanded: boolean;
  onToggle: () => void;
  onCreated: () => void;
}

function GroupCard({ group, isExpanded, onToggle, onCreated }: GroupCardProps) {
  const [showSpinoffDialog, setShowSpinoffDialog] = useState(false);
  const [showUserDialog, setShowUserDialog] = useState(false);
  const { data: spinoffs = [], isLoading: loadingSpinoffs } = useGroupSpinoffs(
    group.id,
    isExpanded,
  );
  const { data: users = [], isLoading: loadingUsers } = useGroupUsers(group.id, isExpanded);
  const activeUsers = users.filter((u) => u.status === 'active').length;
  const inactiveUsers = users.length - activeUsers;
  const assignedRoles = uniqueSorted(users.flatMap((u) => u.roles?.map((r) => r.name) ?? []));

  return (
    <Card data-testid={`group-card-${group.id}`}>
      <CardHeader className="flex flex-row items-start justify-between gap-3 space-y-0">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <Building2 className="size-4 shrink-0 text-primary" aria-hidden="true" />
            <CardTitle className="truncate text-base">{group.name}</CardTitle>
            <Badge variant="default" className="text-[10px]">
              Owner
            </Badge>
          </div>
          <CardDescription className="mt-1 flex flex-wrap items-center gap-2 text-xs">
            <span className="font-mono">{group.slug}</span>
            {group.plan && (
              <>
                <span aria-hidden="true">|</span>
                <span>Plan: {group.plan}</span>
              </>
            )}
            {typeof group.users_count === 'number' && (
              <>
                <span aria-hidden="true">|</span>
                <span className="flex items-center gap-1">
                  <Users className="size-3" aria-hidden="true" />
                  {group.users_count} usuarios
                </span>
              </>
            )}
          </CardDescription>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button
            size="sm"
            variant="outline"
            onClick={() => setShowSpinoffDialog(true)}
            data-testid={`group-add-company-${group.id}`}
          >
            <Plus className="size-3.5" /> Agregar empresa
          </Button>
          <Button
            size="sm"
            variant="outline"
            onClick={() => setShowUserDialog(true)}
            data-testid={`group-add-user-${group.id}`}
          >
            <UserPlus className="size-3.5" /> Agregar usuario
          </Button>
          <Button
            size="icon-sm"
            variant="ghost"
            onClick={onToggle}
            aria-label={isExpanded ? 'Contraer' : 'Expandir'}
            aria-expanded={isExpanded}
            data-testid={`group-toggle-${group.id}`}
          >
            {isExpanded ? (
              <ChevronDown className="size-4" />
            ) : (
              <ChevronRight className="size-4" />
            )}
          </Button>
        </div>
      </CardHeader>

      {isExpanded && (
        <CardContent className="space-y-4 border-t border-border pt-3">
          <div className="grid gap-2 md:grid-cols-3">
            <SummaryTile
              label="Empresas"
              value={loadingSpinoffs ? '...' : String(spinoffs.length)}
              detail="Spinoffs bajo este grupo"
            />
            <SummaryTile
              label="Usuarios"
              value={loadingUsers ? '...' : String(users.length)}
              detail={`${activeUsers} activos${inactiveUsers > 0 ? `, ${inactiveUsers} inactivos` : ''}`}
            />
            <SummaryTile
              label="Roles en uso"
              value={loadingUsers ? '...' : String(assignedRoles.length)}
              detail={assignedRoles.slice(0, 3).join(', ') || 'Sin roles asignados'}
            />
          </div>

          <div>
            <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">
              Empresas hijas (spinoffs)
            </h4>
            {loadingSpinoffs ? (
              <Spinner label="Cargando empresas..." />
            ) : spinoffs.length === 0 ? (
              <p className="py-2 text-xs text-text-muted">
                Este grupo aun no tiene empresas hijas. Usa "Agregar empresa" para crear una.
              </p>
            ) : (
              <ul className="space-y-1.5" data-testid={`group-spinoffs-${group.id}`}>
                {spinoffs.map((s) => (
                  <SpinoffRow key={s.id} spinoff={s} />
                ))}
              </ul>
            )}
          </div>

          <div>
            <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
              <div>
                <h4 className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                  Usuarios de la organizacion
                </h4>
                <p className="mt-0.5 text-xs text-text-muted">
                  Gestiona roles y scopes desde la ficha del usuario. Esta vista cruza grupo y empresas hijas.
                </p>
              </div>
              {users.length > 0 && (
                <Badge variant="info" className="text-[10px]">
                  {users.length} visibles
                </Badge>
              )}
            </div>
            {loadingUsers ? (
              <Spinner label="Cargando usuarios..." />
            ) : users.length === 0 ? (
              <p className="py-2 text-xs text-text-muted">
                Aun no hay usuarios en este grupo o sus empresas hijas. Usa "Agregar usuario".
              </p>
            ) : (
              <ul className="space-y-1.5" data-testid={`group-users-${group.id}`}>
                {users.map((u) => (
                  <UserRow key={u.id} user={u} />
                ))}
              </ul>
            )}
          </div>

          <div className="rounded border border-border bg-bg/30 px-3 py-2">
            <div className="flex items-start gap-2">
              <ShieldCheck className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
              <div className="min-w-0">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                  Gobierno de permisos
                </h4>
                <p className="mt-1 text-xs text-text-muted">
                  Los roles respetan el catalogo jerarquico de permisos. Owner administra el grupo;
                  Administrador opera su empresa y sus usuarios segun permisos efectivos y scopes.
                </p>
              </div>
            </div>
          </div>

          <div className="rounded border border-border bg-bg/30 px-3 py-2">
            <div className="mb-2 flex items-start gap-2">
              <Boxes className="mt-0.5 size-4 shrink-0 text-primary" aria-hidden="true" />
              <div className="min-w-0">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-text-secondary">
                  Catalogo compartido del grupo
                </h4>
                <p className="mt-0.5 text-xs text-text-muted">
                  Productos, listas de precios, metodos de pago y tasas viven en el grupo y se
                  replican a cada tienda. El stock sigue siendo independiente por empresa.
                </p>
              </div>
            </div>
            <ul
              className="grid gap-2 md:grid-cols-2"
              data-testid={`group-shared-catalog-${group.id}`}
            >
              <SharedCatalogLink
                href="/inventory"
                icon={<Package className="size-3.5" aria-hidden="true" />}
                label="Productos"
                hint="SKU, marca, categorias y tags a nivel grupo."
              />
              <SharedCatalogLink
                href="/inventory/catalogs"
                icon={<Tag className="size-3.5" aria-hidden="true" />}
                label="Catalogos"
                hint="Marcas, categorias y tags compartidos."
              />
              <SharedCatalogLink
                href="/inventory/currency"
                icon={<TrendingUp className="size-3.5" aria-hidden="true" />}
                label="Tipos de tasa"
                hint="BCV, paralelo y tasa tienda del grupo."
              />
              <SharedCatalogLink
                href="/payment-methods"
                icon={<CreditCard className="size-3.5" aria-hidden="true" />}
                label="Metodos de pago"
                hint="Efectivo, tarjeta, transferencia y pago movil del grupo."
              />
            </ul>
            <p
              className="mt-2 text-[11px] text-text-muted"
              data-testid={`group-shared-catalog-policy-${group.id}`}
            >
              Politica: solo el Owner del grupo puede crear, editar o desactivar
              el catalogo compartido. Las sucursales (spinoffs) lo consumen en
              lectura; sus administradores ven los mismos productos, listas,
              tasas y metodos de pago, pero no los pueden modificar.
            </p>
            <SharedCatalogProducts groupId={group.id} />
          </div>
        </CardContent>
      )}

      <CreateSpinoffDialog
        open={showSpinoffDialog}
        onOpenChange={setShowSpinoffDialog}
        group={group}
        onCreated={() => {
          setShowSpinoffDialog(false);
          onCreated();
        }}
      />
      <CreateGroupUserDialog
        open={showUserDialog}
        onOpenChange={setShowUserDialog}
        group={group}
        spinoffs={spinoffs}
        onCreated={() => {
          setShowUserDialog(false);
          onCreated();
        }}
      />
    </Card>
  );
}

function SummaryTile({
  label,
  value,
  detail,
}: {
  label: string;
  value: string;
  detail: string;
}) {
  return (
    <div className="rounded border border-border bg-bg/30 px-3 py-2">
      <p className="text-[11px] font-semibold uppercase tracking-wide text-text-secondary">
        {label}
      </p>
      <p className="mt-1 text-lg font-semibold text-text-primary">{value}</p>
      <p className="truncate text-xs text-text-muted">{detail}</p>
    </div>
  );
}

/**
 * Lista compacta de los productos maestros del grupo con checkmarks por
 * spinoff. Carga via useGroupSharedCatalog (endpoint solo Owners).
 */
function SharedCatalogProducts({ groupId }: { groupId: number }) {
  const { data, isLoading, isError, error } = useGroupSharedCatalog(groupId);

  if (isLoading) {
    return (
      <p className="mt-3 text-xs text-text-muted" data-testid={`shared-catalog-loading-${groupId}`}>
        Cargando catalogo maestro...
      </p>
    );
  }

  if (isError) {
    return (
      <p
        className="mt-3 text-xs text-danger"
        data-testid={`shared-catalog-error-${groupId}`}
      >
        No se pudo cargar el catalogo maestro: {error instanceof Error ? error.message : 'error'}
      </p>
    );
  }

  if (!data || data.products.length === 0) {
    return (
      <p
        className="mt-3 text-xs text-text-muted"
        data-testid={`shared-catalog-empty-${groupId}`}
      >
        Aun no hay productos maestros en este grupo. Crea uno desde la pagina de inventario
        del grupo para empezar a replicarlo a las tiendas hijas.
      </p>
    );
  }

  return (
    <div
      className="mt-3 space-y-2"
      data-testid={`shared-catalog-table-${groupId}`}
    >
      <div
        className="grid items-center gap-2 text-[10px] font-semibold uppercase tracking-wide text-text-muted"
        style={{ gridTemplateColumns: `minmax(180px, 1fr) repeat(${data.spinoffs.length}, minmax(80px, auto))` }}
      >
        <span>Producto maestro</span>
        {data.spinoffs.map((s) => (
          <span key={s.id} className="truncate text-center" title={s.name}>
            {s.slug}
          </span>
        ))}
      </div>
      {data.products.map((entry) => (
        <div
          key={entry.master.id}
          className="grid items-center gap-2 rounded border border-border bg-surface px-2 py-1.5 text-xs"
          style={{ gridTemplateColumns: `minmax(180px, 1fr) repeat(${data.spinoffs.length}, minmax(80px, auto))` }}
          data-testid={`shared-catalog-row-${entry.master.id}`}
        >
          <div className="min-w-0">
            <p className="truncate font-medium text-text-primary">{entry.master.name}</p>
            <p className="font-mono text-[10px] text-text-muted">{entry.master.sku ?? '-'}</p>
          </div>
          {entry.copies.map((copy) => {
            const status = !copy.propagated
              ? 'pendiente'
              : copy.is_catalog_active === false
                ? 'desactivado'
                : copy.is_active === false
                  ? 'inactivo'
                  : 'ok';
            const variant =
              status === 'ok'
                ? 'success'
                : status === 'pendiente'
                  ? 'default'
                  : 'warning';
            return (
              <div key={copy.spinoff_id} className="flex items-center justify-center">
                <Badge variant={variant} className="text-[10px]">
                  {status}
                </Badge>
              </div>
            );
          })}
        </div>
      ))}
    </div>
  );
}

function SharedCatalogLink({
  href,
  icon,
  label,
  hint,
}: {
  href: string;
  icon: React.ReactNode;
  label: string;
  hint: string;
}) {
  return (
    <li>
      <a
        href={href}
        className="group flex items-start gap-2 rounded border border-border bg-surface px-3 py-2 text-sm transition-colors hover:border-primary/40 hover:bg-primary/5"
        data-testid={`group-shared-link-${label.toLowerCase().replace(/\s+/g, '-')}`}
      >
        <span className="mt-0.5 text-primary" aria-hidden="true">
          {icon}
        </span>
        <span className="min-w-0 flex-1">
          <span className="block font-medium text-text-primary">{label}</span>
          <span className="mt-0.5 block text-xs text-text-muted">{hint}</span>
        </span>
        <ExternalLink
          className="size-3.5 shrink-0 text-text-muted transition-colors group-hover:text-primary"
          aria-hidden="true"
        />
      </a>
    </li>
  );
}

function UserRow({ user }: { user: GroupUser }) {
  const tenantSummary = summarizeUserTenants(user);
  const roleSummary = summarizeUserRoles(user);

  return (
    <li
      className={cn(
        'flex flex-col gap-3 rounded border border-border bg-bg/30 px-3 py-2 md:flex-row md:items-center md:justify-between',
        user.status !== 'active' && 'opacity-60',
      )}
      data-testid={`group-user-${user.id}`}
    >
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <p className="truncate text-sm font-medium">{user.name}</p>
          <Badge
            variant={user.status === 'active' ? 'success' : 'warning'}
            className="text-[10px]"
          >
            {user.status}
          </Badge>
        </div>
        <p className="font-mono text-xs text-text-muted">{user.email}</p>
        {user.tenants && user.tenants.length > 0 && (
          <div className="mt-1 flex flex-wrap gap-1">
            {user.tenants.map((t) => (
              <Badge
                key={t.id}
                variant={t.is_group ? 'default' : 'info'}
                className="text-[10px]"
              >
                {t.slug}
              </Badge>
            ))}
          </div>
        )}
      </div>
      <div className="grid shrink-0 gap-2 text-xs text-text-muted md:min-w-80 md:grid-cols-[1fr_auto] md:items-center">
        <div className="min-w-0">
          <p className="truncate">
            <span className="font-medium text-text-secondary">Roles:</span> {roleSummary}
          </p>
          <p className="truncate">
            <span className="font-medium text-text-secondary">Alcance:</span> {tenantSummary}
          </p>
        </div>
        <Button asChild size="sm" variant="outline">
          <a href={`/users/${user.id}?scope=organization`}>
            <ExternalLink className="size-3.5" /> Ficha
          </a>
        </Button>
      </div>
    </li>
  );
}

function summarizeUserRoles(user: GroupUser): string {
  const roles = uniqueSorted(user.roles?.map((r) => r.name) ?? []);

  return roles.length > 0 ? roles.join(', ') : 'Sin rol';
}

function summarizeUserTenants(user: GroupUser): string {
  const tenants = uniqueSorted(user.tenants?.map((t) => t.slug) ?? []);

  return tenants.length > 0 ? tenants.join(', ') : 'Sin empresa';
}

function uniqueSorted(values: string[]): string[] {
  return [...new Set(values.filter(Boolean))].sort((a, b) => a.localeCompare(b));
}

function SpinoffRow({ spinoff }: { spinoff: TenantSpinoff }) {
  return (
    <li
      className={cn(
        'flex items-center justify-between gap-3 rounded border border-border bg-bg/30 px-3 py-2',
        spinoff.status !== 'active' && 'opacity-60',
      )}
      data-testid={`spinoff-${spinoff.id}`}
    >
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">{spinoff.name}</p>
        <p className="font-mono text-xs text-text-muted">{spinoff.slug}</p>
      </div>
      <div className="flex shrink-0 items-center gap-2 text-xs text-text-muted">
        {typeof spinoff.users_count === 'number' && (
          <span className="flex items-center gap-1">
            <Users className="size-3" aria-hidden="true" />
            {spinoff.users_count}
          </span>
        )}
        {spinoff.status !== 'active' && (
          <Badge variant="warning" className="text-[10px]">
            {spinoff.status}
          </Badge>
        )}
        {spinoff.status === 'active' && (
          <Badge variant="success" className="text-[10px]">
            activa
          </Badge>
        )}
      </div>
    </li>
  );
}
