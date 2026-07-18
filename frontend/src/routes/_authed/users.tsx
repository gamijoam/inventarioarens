/**
 * Pagina /users: gestion de usuarios del tenant actual (Fase A+B).
 * - Fase A: solo listado.
 * - Fase B: dialogs de crear / editar nombre / cambiar roles /
 *   activar-inactivar. Los dialogs viven aqui para que UsersManager
 *   siga siendo presentacional.
 */
import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';

import { Can } from '@/components/permissions/Can';
import { PageLayout } from '@/components/layout/PageLayout';
import { useSessionStore } from '@/stores/session';
import { PERMISSIONS } from '@/permissions/constants';

import { UsersManager } from '@/features/users/UsersManager';
import { CreateUserDialog } from '@/features/users/dialogs/CreateUserDialog';
import { EditUserDialog } from '@/features/users/dialogs/EditUserDialog';
import { ChangeRolesDialog } from '@/features/users/dialogs/ChangeRolesDialog';
import type { User } from '@/features/users/schemas';

export const Route = createFileRoute('/_authed/users')({
  validateSearch: (search: Record<string, unknown>): { scope: 'tenant' | 'organization' } => ({
    scope: search.scope === 'organization' ? 'organization' : 'tenant',
  }),
  component: UsersPage,
});

function UsersPage() {
  const { scope } = Route.useSearch();
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<User | null>(null);
  const [changingRoles, setChangingRoles] = useState<User | null>(null);
  // Para Fase B: cualquier user logueado con users.update puede editar
  // (los botones especificos ya validan permisos via <Can>).
  const canUpdate = useSessionStore((s) => s.permissions.has(PERMISSIONS.USERS_UPDATE));
  const canChangeStatus = useSessionStore((s) => s.permissions.has(PERMISSIONS.USERS_UPDATE));
  const canEdit = canUpdate || canChangeStatus;

  return (
    <PageLayout
      title="Usuarios"
      description="Personas con acceso a esta empresa u organizacion, segun el alcance seleccionado."
    >
      <Can
        I={PERMISSIONS.USERS_VIEW}
        fallback={
          <div className="rounded-lg border border-dashed border-warning bg-warning/5 p-3 text-sm text-text-secondary">
            No tienes permiso para ver usuarios. Pide a un administrador que te
            asigne el permiso <code>users.view</code>.
          </div>
        }
      >
        <UsersManager
          initialScope={scope}
          onCreate={() => setCreating(true)}
          onEdit={(u) => setEditing(u)}
          onChangeRoles={(u) => setChangingRoles(u)}
          canEdit={canEdit}
        />
      </Can>

      <CreateUserDialog
        open={creating}
        onOpenChange={setCreating}
        onCreated={() => {
          // En Fase C con /users/$userId navegamos al detail.
        }}
      />

      {editing && (
        <EditUserDialog
          open={editing !== null}
          onOpenChange={(open) => !open && setEditing(null)}
          user={editing}
        />
      )}

      {changingRoles && (
        <ChangeRolesDialog
          open={changingRoles !== null}
          onOpenChange={(open) => !open && setChangingRoles(null)}
          user={changingRoles}
        />
      )}
    </PageLayout>
  );
}
