/**
 * Pagina /access/roles: gestion de roles y permisos (Fase C).
 * - Listado de roles con TanStack Table.
 * - Dialogs: crear, editar nombre, duplicar, ver/editar permisos, eliminar.
 */
import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';

import { Can } from '@/components/permissions/Can';
import { PageLayout } from '@/components/layout/PageLayout';
import { PERMISSIONS } from '@/permissions/constants';

import { RolesManager } from '@/features/access/RolesManager';
import { RoleEditor } from '@/features/access/RoleEditor';
import { DuplicateRoleDialog } from '@/features/access/DuplicateRoleDialog';
import { RolePermissionsDialog } from '@/features/access/RolePermissionsDialog';
import type { Role } from '@/features/access/api';

export const Route = createFileRoute('/_authed/access/roles')({
  component: RolesPage,
});

function RolesPage() {
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<Role | null>(null);
  const [duplicating, setDuplicating] = useState<Role | null>(null);
  const [editingPerms, setEditingPerms] = useState<Role | null>(null);

  return (
    <PageLayout
      title="Roles y Permisos"
      description="Perfiles del sistema. Cada rol agrupa un conjunto de permisos que se asignan a usuarios."
    >
      <Can
        I={PERMISSIONS.ROLES_VIEW}
        fallback={
          <div className="rounded-lg border border-dashed border-warning bg-warning/5 p-3 text-sm text-text-secondary">
            No tienes permiso para ver roles. Pide a un administrador que te
            asigne el permiso <code>roles.view</code>.
          </div>
        }
      >
        <RolesManager
          onCreate={() => setCreating(true)}
          onEdit={(r) => setEditing(r)}
          onDuplicate={(r) => setDuplicating(r)}
          onEditPermissions={(r) => setEditingPerms(r)}
        />
      </Can>

      <RoleEditor
        open={creating || editing !== null}
        onOpenChange={(open) => {
          if (!open) {
            setCreating(false);
            setEditing(null);
          }
        }}
        role={editing}
        onSaved={() => {
          // El listado se invalida solo via useUpdateRole/useCreateRole.
        }}
      />

      <DuplicateRoleDialog
        open={duplicating !== null}
        onOpenChange={(open) => !open && setDuplicating(null)}
        sourceRole={duplicating}
      />

      <RolePermissionsDialog
        open={editingPerms !== null}
        onOpenChange={(open) => !open && setEditingPerms(null)}
        role={editingPerms}
      />
    </PageLayout>
  );
}