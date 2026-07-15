/**
 * Pagina /access/permissions: vista global de los permisos del sistema.
 * Util para administradores que quieren ver de un vistazo todos los modulos
 * y sus acciones disponibles. Reutiliza el PermissionTree en modo solo
 * lectura (no se puede editar nada desde aca; los permisos se asignan
 * via roles).
 */
import { ShieldCheck } from 'lucide-react';
import { createFileRoute } from '@tanstack/react-router';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';

import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';

import { usePermissionCatalog } from '@/features/access/api';
import { PermissionTree } from '@/features/access/PermissionTree';

export const Route = createFileRoute('/_authed/access/permissions')({
  component: PermissionsPage,
});

function PermissionsPage() {
  const { data, isLoading, isError } = usePermissionCatalog();
  const totalPermissions = data?.total_permissions ?? 0;
  const totalModules = data?.total_modules ?? 0;

  return (
    <PageLayout
      title="Catalogo de Permisos"
      description={`Vista global: ${totalPermissions} permisos en ${totalModules} modulos. Solo lectura; los permisos se asignan a usuarios a traves de roles.`}
    >
      <Can
        I={PERMISSIONS.ROLES_VIEW}
        fallback={
          <div className="rounded-lg border border-dashed border-warning bg-warning/5 p-3 text-sm text-text-secondary">
            No tienes permiso para ver el catalogo. Pide a un administrador que
            te asigne el permiso <code>roles.view</code>.
          </div>
        }
      >
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <ShieldCheck className="size-4" aria-hidden="true" />
              Permisos del sistema
            </CardTitle>
            <CardDescription>
              Cada modulo agrupa las acciones (verb) disponibles. Para asignar
              permisos a usuarios, configura un rol en la pagina
              <strong> Roles y Permisos</strong>.
            </CardDescription>
          </CardHeader>
          <CardContent>
            {isLoading && <Skeleton className="h-96 w-full" />}
            {isError && (
              <p className="text-sm text-danger">No se pudo cargar el catalogo de permisos.</p>
            )}
            {data && (
              <PermissionTree
                modules={data.modules}
                selected={new Set()}
                onToggle={() => {
                  // No-op: solo lectura.
                }}
                disabled
              />
            )}
          </CardContent>
        </Card>
      </Can>
    </PageLayout>
  );
}
