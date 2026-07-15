/**
 * Pagina /access/roles/$roleId: detalle de un rol con tabs.
 * - General: info basica + contador de permisos + meta del rol.
 * - Permisos: solo vista de los permisos asignados (la edicion se hace
 *   desde el listado con el dialog de permisos, que es mas conveniente
 *   para editarlos todos a la vez con el PermissionTree).
 */
import { Link, createFileRoute, useNavigate } from '@tanstack/react-router';
import { ArrowLeft, ShieldCheck } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';
import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';

import { useRole, useRolePreview } from '@/features/access/api';
import { ProtectedRoleBadge } from '@/features/access/ProtectedRoleBadge';
import { PermissionTree } from '@/features/access/PermissionTree';
import { usePermissionCatalog } from '@/features/access/api';

export const Route = createFileRoute('/_authed/access/roles/$roleId')({
  component: RoleDetailPage,
});

function RoleDetailPage() {
  const { roleId } = Route.useParams();
  const id = Number.parseInt(roleId, 10);
  const navigate = useNavigate();
  const { data: role, isLoading, isError } = useRole(id);
  const { data: preview } = useRolePreview(id);
  const { data: catalog } = usePermissionCatalog();
  const [tab, setTab] = useState('general');

  if (isLoading) {
    return (
      <PageLayout title="Cargando rol...">
        <Skeleton className="h-32 w-full" />
        <Skeleton className="h-64 w-full" />
      </PageLayout>
    );
  }

  if (isError || !role) {
    return (
      <PageLayout title="Rol no encontrado">
        <div className="rounded-lg border border-dashed border-border bg-bg/30 p-4 text-sm text-text-muted">
          El rol no existe o no tienes permiso para verlo.
          <Button variant="outline" size="sm" className="ml-2" onClick={() => navigate({ to: '/access/roles' })}>
            <ArrowLeft className="size-4" /> Volver
          </Button>
        </div>
      </PageLayout>
    );
  }

  const isProtected = role.is_protected ?? false;
  const permissionCount = role.permissions?.length ?? preview?.data?.permission_count ?? 0;
  const moduleCount = preview?.data?.module_count ?? 0;
  const selected = new Set(role.permissions ?? []);

  return (
    <PageLayout
      title={role.name}
      description={`${permissionCount} permisos en ${moduleCount} modulos${isProtected ? ' - rol del sistema' : ''}`}
      actions={
        <div className="flex items-center gap-2">
          {isProtected && <ProtectedRoleBadge isProtected />}
          <Can I={PERMISSIONS.ROLES_VIEW}>
            <Button asChild variant="outline" size="sm">
              <Link to="/access/roles">
                <ArrowLeft className="size-4" /> Volver al listado
              </Link>
            </Button>
          </Can>
        </div>
      }
    >
      <Tabs value={tab} onValueChange={setTab}>
        <TabsList>
          <TabsTrigger value="general">General</TabsTrigger>
          <TabsTrigger value="permissions">
            Permisos
            <Badge variant="info" className="ml-1 text-[10px]">{permissionCount}</Badge>
          </TabsTrigger>
        </TabsList>

        <TabsContent value="general" className="space-y-4">
          <Card>
            <CardHeader>
              <CardTitle>Identidad</CardTitle>
            </CardHeader>
            <CardContent className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
              <div>
                <div className="text-xs uppercase tracking-wide text-text-muted">Nombre</div>
                <div className="mt-1 font-medium">{role.name}</div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wide text-text-muted">Permisos</div>
                <div className="mt-1 font-medium tabular-nums">{permissionCount}</div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wide text-text-muted">Modulos</div>
                <div className="mt-1 font-medium tabular-nums">{moduleCount}</div>
              </div>
              <div>
                <div className="text-xs uppercase tracking-wide text-text-muted">Tipo</div>
                <div className="mt-1 font-medium">
                  {isProtected ? 'Rol del sistema' : 'Rol custom del tenant'}
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="permissions" className="space-y-3">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2">
                <ShieldCheck className="size-4" aria-hidden="true" />
                Permisos del rol
              </CardTitle>
              <CardDescription>
                Solo lectura. Para editarlos, usa el dialog &quot;Permisos&quot; del listado
                (click en el boton shield-check).
              </CardDescription>
            </CardHeader>
            <CardContent>
              {catalog ? (
                <PermissionTree
                  modules={catalog.modules}
                  selected={selected}
                  onToggle={() => {
                    // No-op: solo lectura.
                  }}
                  disabled
                  initialSearch=""
                />
              ) : (
                <Skeleton className="h-64 w-full" />
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>
    </PageLayout>
  );
}