/**
 * UserDetailTabs: tabs General / Overrides / Scopes para la pagina
 * de detalle de un usuario.
 *
 * - General: info basica + roles + permisos efectivos (diff base vs efectivos).
 * - Overrides: lista de allow/deny + agregar/quitar con PermissionPicker.
 * - Scopes: gestion de branches/warehouses/customer-groups/vendor-of.
 */
import { useState } from 'react';
import { Clock, Lock, Pencil, ShieldCheck, UserCircle } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';

import { useEffectivePermissions, useUserAudits, userKeys, type User } from './api';
import { UserOverridesTab } from './UserOverridesTab';
import { UserScopesTab } from './UserScopesTab';
import { EditUserDialog } from './dialogs/EditUserDialog';
import { ChangeRolesDialog } from './dialogs/ChangeRolesDialog';
import { ProtectedRoleBadge } from '@/features/access/ProtectedRoleBadge';

interface UserDetailTabsProps {
  user: User;
}

export function UserDetailTabs({ user }: UserDetailTabsProps) {
  const [tab, setTab] = useState('general');

  return (
      <Tabs value={tab} onValueChange={setTab}>
      <TabsList>
        <TabsTrigger value="general">General</TabsTrigger>
        <TabsTrigger value="overrides">Overrides</TabsTrigger>
        <TabsTrigger value="scopes">Scopes</TabsTrigger>
        <TabsTrigger value="activity">Actividad</TabsTrigger>
      </TabsList>

      <TabsContent value="general" className="space-y-4">
        <GeneralTab user={user} />
      </TabsContent>

      <TabsContent value="overrides" className="space-y-4">
        <UserOverridesTab userId={user.id} />
      </TabsContent>

      <TabsContent value="scopes" className="space-y-4">
        <UserScopesTab userId={user.id} />
      </TabsContent>

      <TabsContent value="activity" className="space-y-4">
        <ActivityTab userId={user.id} />
      </TabsContent>
    </Tabs>
  );
}

function GeneralTab({ user }: { user: User }) {
  const [editingUser, setEditingUser] = useState(false);
  const [changingRoles, setChangingRoles] = useState(false);
  const qc = useQueryClient();
  const effective = useEffectivePermissions(user.id);
  const isProtected = user.roles.some((r: { id: number; name: string; is_protected?: boolean }) => r.is_protected);

  const handleUpdated = () => {
    qc.invalidateQueries({ queryKey: userKeys.detail(user.id) });
    qc.invalidateQueries({ queryKey: userKeys.lists() });
  };

  return (
    <>
      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0">
            <CardTitle>Identidad</CardTitle>
            <Can I={PERMISSIONS.USERS_UPDATE}>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                className="gap-1 text-xs"
                onClick={() => setEditingUser(true)}
                data-testid="detail-edit-user-btn"
              >
                <Pencil className="size-3.5" aria-hidden="true" />
                Editar
              </Button>
            </Can>
          </CardHeader>
          <CardContent className="space-y-2 text-sm">
            <Row label="Nombre">{user.name}</Row>
            <Row label="Email">
              <code className="rounded bg-bg px-1.5 py-0.5">{user.email}</code>
            </Row>
            <Row label="Estado">
              <Badge variant={user.status === 'active' ? 'success' : 'default'}>
                {user.status === 'active' ? 'Activo' : 'Inactivo'}
              </Badge>
            </Row>
            <Row label="Roles">
              <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-1">
                  {user.roles.length === 0 ? (
                    <span className="text-text-muted">Sin rol</span>
                  ) : (
                    user.roles.map((r) => (
                      <Badge key={r.id} variant="info" className="gap-1 text-xs">
                        {r.name}
                        {r.is_protected && (
                          <Lock className="size-2.5" aria-hidden="true" />
                        )}
                      </Badge>
                    ))
                  )}
                  {isProtected && (
                    <span className="text-xs text-text-muted">(del sistema)</span>
                  )}
                </div>
                <Can I={PERMISSIONS.USERS_UPDATE}>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className="w-fit gap-1 text-xs"
                    onClick={() => setChangingRoles(true)}
                    data-testid="detail-change-roles-btn"
                  >
                    <ShieldCheck className="size-3.5" aria-hidden="true" />
                    Cambiar roles
                  </Button>
                </Can>
              </div>
            </Row>
          </CardContent>
        </Card>

      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <ShieldCheck className="size-4" aria-hidden="true" />
            Permisos efectivos
          </CardTitle>
          <CardDescription>
            Lo que este usuario puede hacer realmente (roles + extras - denies).
          </CardDescription>
        </CardHeader>
        <CardContent>
          {effective.isLoading && <Skeleton className="h-32 w-full" />}
          {effective.isError && (
            <p className="text-sm text-danger">No se pudo cargar los permisos efectivos.</p>
          )}
          {effective.data && (
            <div className="space-y-2">
              <div className="flex flex-wrap gap-2 text-sm">
                <Badge variant="info">{effective.data.permission_count} efectivos</Badge>
                <Badge variant="default">{effective.data.base_count} base del rol</Badge>
                {effective.data.extras.length > 0 && (
                  <Badge variant="success">+{effective.data.extras.length} extras</Badge>
                )}
                {effective.data.denied.length > 0 && (
                  <Badge variant="warning">-{effective.data.denied.length} denies</Badge>
                )}
              </div>
              {effective.data.extras.length > 0 && (
                <details>
                  <summary className="cursor-pointer text-xs text-text-muted">
                    Extras (allow)
                  </summary>
                  <ul className="ml-4 mt-1 space-y-0.5 text-xs">
                    {effective.data.extras.map((p) => (
                      <li key={p}><code>{p}</code></li>
                    ))}
                  </ul>
                </details>
              )}
              {effective.data.denied.length > 0 && (
                <details>
                  <summary className="cursor-pointer text-xs text-text-muted">
                    Denied
                  </summary>
                  <ul className="ml-4 mt-1 space-y-0.5 text-xs">
                    {effective.data.denied.map((p) => (
                      <li key={p}><code>{p}</code></li>
                    ))}
                  </ul>
                </details>
              )}
            </div>
          )}
        </CardContent>
      </Card>
    </div>

      {editingUser && (
        <EditUserDialog
          open={editingUser}
          onOpenChange={setEditingUser}
          user={user}
          onUpdated={handleUpdated}
        />
      )}

      {changingRoles && (
        <ChangeRolesDialog
          open={changingRoles}
          onOpenChange={setChangingRoles}
          user={user}
          onUpdated={handleUpdated}
        />
      )}
    </>
  );
}

function Row({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="flex items-start gap-2">
      <span className="w-24 shrink-0 text-xs uppercase tracking-wide text-text-muted">
        {label}
      </span>
      <div className="flex-1">{children}</div>
    </div>
  );
}

// Marcar el badge de sistema como importado para que el linter no se queje
void ProtectedRoleBadge;
void UserCircle;

function ActivityTab({ userId }: { userId: number }) {
  const { data, isLoading, isError } = useUserAudits(userId);

  if (isLoading) return <Skeleton className="h-32 w-full" />;
  if (isError) {
    return <p className="text-sm text-danger">No se pudo cargar la actividad.</p>;
  }
  if (!data || data.data.length === 0) {
    return (
      <Card>
        <CardContent className="p-6 text-center text-sm text-text-muted">
          <Clock className="mx-auto mb-2 size-6 text-text-muted" aria-hidden="true" />
          Sin actividad registrada todavia.
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Clock className="size-4" aria-hidden="true" />
          Actividad reciente
        </CardTitle>
        <CardDescription>Ultimos {data.data.length} cambios (max 50).</CardDescription>
      </CardHeader>
      <CardContent>
        <ul className="divide-y divide-border">
          {data.data.map((entry) => (
            <li key={entry.id} className="flex items-start gap-3 py-2">
              <Badge variant="info" className="shrink-0 text-xs">
                {entry.action}
              </Badge>
              <div className="flex-1">
                <div className="text-xs text-text-muted">
                  {entry.created_at ? new Date(entry.created_at).toLocaleString() : ''}
                </div>
                {entry.new_values && (
                  <pre className="mt-1 max-h-32 overflow-auto rounded bg-bg p-2 font-mono text-xs text-text-secondary">
                    {JSON.stringify(entry.new_values, null, 2)}
                  </pre>
                )}
              </div>
            </li>
          ))}
        </ul>
      </CardContent>
    </Card>
  );
}