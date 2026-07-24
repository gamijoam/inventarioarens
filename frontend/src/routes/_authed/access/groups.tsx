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
 *
 * Si el usuario esta parado sobre una empresa normal sin hijos, la
 * pagina ofrece un atajo para "convertir empresa a grupo" en lugar de
 * obligarlo a crear un grupo nuevo.
 */
import { useState } from 'react';
import { createFileRoute, useNavigate } from '@tanstack/react-router';
import { ArrowUpRight, Building2 } from 'lucide-react';

import { Button } from '@/components/ui/Button';
import { PageLayout } from '@/components/layout/PageLayout';

import { GroupsTree } from '@/features/access/GroupsTree';
import { CreateGroupDialog } from '@/features/access/CreateGroupDialog';
import { CreateSpinoffDialog } from '@/features/access/CreateSpinoffDialog';
import { PromoteToGroupDialog } from '@/features/access/PromoteToGroupDialog';
import { useSessionStore } from '@/stores/session';
import type { TenantGroup } from '@/features/access/tenantGroupsApi';

export const Route = createFileRoute('/_authed/access/groups')({
  component: GroupsPage,
});

function GroupsPage() {
  const [creatingGroup, setCreatingGroup] = useState(false);
  const [pendingSpinoffFor, setPendingSpinoffFor] = useState<TenantGroup | null>(null);
  const [promoting, setPromoting] = useState(false);
  const tenant = useSessionStore((s) => s.tenant);
  const navigate = useNavigate();

  const canOfferPromote =
    tenant !== null && tenant.is_group === false && tenant.parent_id == null;

  return (
    <PageLayout
      title="Organizaciones multi-empresa"
      description="Crea tu grupo (holding) y agrega empresas hijas (sucursales, regionales, marcas blancas) que comparten administracion."
      icon={<Building2 className="size-5" aria-hidden="true" />}
    >
      {canOfferPromote && tenant && (
        <div
          className="flex items-start justify-between gap-3 rounded-md border border-primary/30 bg-primary/5 p-3 text-sm"
          data-testid="promote-current-tenant"
        >
          <div className="min-w-0 flex-1">
            <p className="font-semibold">Tu empresa es independiente</p>
            <p className="mt-0.5 text-xs text-text-secondary">
              Si quieres operar varias sucursales con el mismo catalogo, precios y tasas,
              conviertela en grupo y luego agrega las tiendas hijas.
            </p>
          </div>
          <Button
            size="sm"
            onClick={() => setPromoting(true)}
            data-testid="promote-open"
          >
            <ArrowUpRight className="size-3.5" /> Convertir a grupo
          </Button>
        </div>
      )}

      <GroupsTree onCreateGroup={() => setCreatingGroup(true)} />

      <CreateGroupDialog
        open={creatingGroup}
        onOpenChange={setCreatingGroup}
        onCreated={() => {
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

      {canOfferPromote && tenant && (
        <PromoteToGroupDialog
          open={promoting}
          onOpenChange={setPromoting}
          tenant={{ id: tenant.id, name: tenant.name, slug: tenant.slug }}
          onPromoted={(group) => {
            setPromoting(false);
            // Refrescar sesion para que Topbar vea is_group=true y aparezca "Grupo".
            useSessionStore.getState().setTenant({
              ...tenant,
              is_group: true,
              parent_id: null,
            });
            void navigate({ to: '/access/groups' });
            void group;
          }}
        />
      )}
    </PageLayout>
  );
}