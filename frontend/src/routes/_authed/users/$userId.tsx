/**
 * Pagina /users/$userId: detalle de un usuario con tabs.
 * - General: identidad + permisos efectivos (diff base vs efectivos).
 * - Overrides: gestion de permisos extra/deny por usuario.
 * - Scopes: gestion de branches/warehouses/customer-groups/vendor-of.
 */
import { Link, createFileRoute, useNavigate } from '@tanstack/react-router';
import { ArrowLeft } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { Skeleton } from '@/components/ui/Skeleton';
import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';

import { useUser } from '@/features/users/api';
import { UserDetailTabs } from '@/features/users/UserDetailTabs';

export const Route = createFileRoute('/_authed/users/$userId')({
  validateSearch: (search: Record<string, unknown>): { scope: 'tenant' | 'organization' } => ({
    scope: search.scope === 'organization' ? 'organization' : 'tenant',
  }),
  component: UserDetailPage,
});

function UserDetailPage() {
  const { userId } = Route.useParams();
  const { scope } = Route.useSearch();
  const id = Number.parseInt(userId, 10);
  const navigate = useNavigate();
  const { data: user, isLoading, isError } = useUser(id, scope);

  if (isLoading) {
    return (
      <PageLayout title="Cargando usuario...">
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-64 w-full" />
      </PageLayout>
    );
  }

  if (isError || !user) {
    return (
      <PageLayout title="Usuario no encontrado">
        <div className="rounded-lg border border-dashed border-border bg-bg/30 p-4 text-sm text-text-muted">
          El usuario no existe o no tienes permiso para verlo.
          <Button
            variant="outline"
            size="sm"
            className="ml-2"
            onClick={() => navigate({ to: '/users', search: { scope } })}
          >
            <ArrowLeft className="size-4" /> Volver
          </Button>
        </div>
      </PageLayout>
    );
  }

  return (
    <PageLayout
      title={user.name}
      description={`${user.email} - ${user.status === 'active' ? 'Activo' : 'Inactivo'}`}
      actions={
        <Can I={PERMISSIONS.USERS_VIEW}>
          <Button asChild variant="outline" size="sm">
            <Link to="/users" search={{ scope }}>
              <ArrowLeft className="size-4" /> Volver
            </Link>
          </Button>
        </Can>
      }
    >
      <UserDetailTabs user={user} />
    </PageLayout>
  );
}
