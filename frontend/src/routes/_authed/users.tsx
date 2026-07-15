/**
 * Pagina /users: gestion de usuarios del tenant actual (Fase A).
 * Solo listado por ahora. Edicion, creacion y cambio de roles llegan
 * en Fase B.
 *
 * Backend: GET /api/access/users (paginated, filtrable).
 */
import { createFileRoute } from '@tanstack/react-router';

import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';

import { UsersManager } from '@/features/users/UsersManager';

export const Route = createFileRoute('/_authed/users')({
  component: UsersPage,
});

function UsersPage() {
  return (
    <PageLayout
      title="Usuarios"
      description="Personas con acceso a esta empresa. Cada usuario pertenece al tenant actual con un rol y un estado."
    >
      <Can
        I={PERMISSIONS.USERS_VIEW}
        fallback={
          <div className="rounded-lg border border-dashed border-warning bg-warning/5 p-3 text-sm text-text-secondary">
            No tienes permiso para ver usuarios. Pide a un administrador que te asigne
            el permiso <code>users.view</code>.
          </div>
        }
      >
        <UsersManager />
      </Can>
    </PageLayout>
  );
}