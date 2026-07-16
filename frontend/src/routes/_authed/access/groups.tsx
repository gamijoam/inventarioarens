/**
 * Pagina /access/groups: gestion de organizaciones multi-empresa (Fase 2).
 *
 * Muestra el arbol jerarquico:
 *   Tenant Group (is_group=true, parent_id=null)
 *     └── Tenant Spinoff (is_group=false, parent_id=group.id)
 *     └── Tenant Spinoff ...
 *
 * Solo los Owners del grupo pueden crear spinoffs. Cualquier usuario
 * autenticado puede crear SU PROPIA organizacion (grupo + tenant inicial)
 * via POST /api/tenant-groups (no requiere ser platform admin).
 */
import { useState } from 'react';
import { createFileRoute } from '@tanstack/react-router';
import { Building2 } from 'lucide-react';

import { PageLayout } from '@/components/layout/PageLayout';

import { GroupsTree } from '@/features/access/GroupsTree';
import { CreateGroupDialog } from '@/features/access/CreateGroupDialog';
import { CreateSpinoffDialog } from '@/features/access/CreateSpinoffDialog';
import type { TenantGroup } from '@/features/access/tenantGroupsApi';

export const Route = createFileRoute('/_authed/access/groups')({
  component: GroupsPage,
});

function GroupsPage() {
  const [creatingGroup, setCreatingGroup] = useState(false);
  const [pendingSpinoffFor, setPendingSpinoffFor] = useState<TenantGroup | null>(null);

  return (
    <PageLayout
      title="Organizaciones multi-empresa"
      description="Crea tu grupo (holding) y agrega empresas hijas (sucursales, regionales, marcas blancas) que comparten administracion."
      icon={<Building2 className="size-5" aria-hidden="true" />}
    >
      <GroupsTree onCreateGroup={() => setCreatingGroup(true)} />

      <CreateGroupDialog
        open={creatingGroup}
        onOpenChange={setCreatingGroup}
        onCreated={() => {
          // El dialog se cierra solo via onOpenChange desde el toast success;
          // aqui solo limpiamos el state pendiente si lo hubiera.
          setCreatingGroup(false);
        }}
      />

      <CreateSpinoffDialog
        open={pendingSpinoffFor !== null}
        onOpenChange={(o) => {
          if (!o) setPendingSpinoffFor(null);
        }}
        group={
          pendingSpinoffFor ?? {
            id: 0,
            name: '',
            slug: '',
            status: 'active',
            is_owner: true,
          }
        }
        onCreated={() => setPendingSpinoffFor(null)}
      />
    </PageLayout>
  );
}