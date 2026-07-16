/**
 * GroupsTree: lista jerarquica de Tenant Groups y sus spinoffs/usuarios.
 *
 * Cada grupo es un card con:
 *  - Header: nombre, slug, plan, contadores
 *  - Lista de spinoffs (empresas hijas) cargados lazy
 *  - Lista de usuarios de la organizacion (grupo + spinoffs) lazy
 *  - Botones "Agregar empresa" y "Agregar usuario" que abren dialogs
 *
 * Acciones disponibles:
 *  - Crear grupo (en el padre de la lista)
 *  - Expandir/colapsar la lista
 *  - Refrescar lista de grupos
 */
import { useState } from 'react';
import {
  Building2,
  ChevronDown,
  ChevronRight,
  Loader2,
  Plus,
  RefreshCw,
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
            <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-text-secondary">
              Usuarios de la organizacion
            </h4>
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

function UserRow({ user }: { user: GroupUser }) {
  return (
    <li
      className={cn(
        'flex items-center justify-between gap-3 rounded border border-border bg-bg/30 px-3 py-2',
        user.status !== 'active' && 'opacity-60',
      )}
      data-testid={`group-user-${user.id}`}
    >
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-medium">{user.name}</p>
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
      <div className="flex shrink-0 items-center gap-2 text-xs text-text-muted">
        {user.roles && user.roles.length > 0 && (
          <span className="flex flex-wrap gap-1">
            {user.roles.map((r) => (
              <Badge key={r.id} variant="info" className="text-[10px]">
                {r.name}
              </Badge>
            ))}
          </span>
        )}
        <Badge
          variant={user.status === 'active' ? 'success' : 'warning'}
          className="text-[10px]"
        >
          {user.status}
        </Badge>
      </div>
    </li>
  );
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