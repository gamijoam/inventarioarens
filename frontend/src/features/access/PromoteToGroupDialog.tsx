/**
 * PromoteToGroupDialog: confirma la conversion de una empresa normal a
 * grupo multi-empresa. El usuario autenticado quedara como Owner del grupo
 * y conserva su rol de Administrador en la empresa inicial.
 *
 * Restricciones que valida el backend:
 *  - La empresa NO debe ser ya un grupo.
 *  - La empresa NO debe tener padre (no estar en otro grupo).
 *  - La empresa NO debe tener empresas hijas.
 */
import { useState } from 'react';
import { ArrowUpRight, Loader2 } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';

import { usePromoteTenant, type TenantGroup } from './tenantGroupsApi';

interface PromoteToGroupDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tenant: { id: number; name: string; slug: string };
  onPromoted: (group: TenantGroup) => void;
}

export function PromoteToGroupDialog({
  open,
  onOpenChange,
  tenant,
  onPromoted,
}: PromoteToGroupDialogProps) {
  const promote = usePromoteTenant();
  const [submitting, setSubmitting] = useState(false);

  async function onSubmit() {
    setSubmitting(true);
    try {
      const res = await promote.mutateAsync(tenant.id);
      const payload = res as { data?: TenantGroup };
      if (payload.data) {
        toast.success(`"${tenant.name}" ahora es un grupo.`);
        onPromoted(payload.data);
      } else {
        onOpenChange(false);
      }
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'No se pudo promover la empresa.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-lg">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <ArrowUpRight className="size-4" /> Convertir empresa a grupo
          </DialogTitle>
          <DialogDescription>
            Esta accion convierte la empresa actual en un grupo multi-empresa. Luego
            podras agregar sucursales (spinoffs) que comparten catalogo y reglas.
          </DialogDescription>
        </DialogHeader>

        <div
          className="space-y-2 rounded-md border border-primary/30 bg-primary/5 p-3 text-xs"
          data-testid="promote-context-banner"
        >
          <p>
            Empresa actual: <strong>{tenant.name}</strong>{' '}
            <span className="font-mono text-text-muted">({tenant.slug})</span>
          </p>
          <p className="text-text-secondary">
            Tu quedaras como Owner del grupo. El stock y movimientos de esta
            empresa siguen siendo solo suyos; los spinoffs tendran su propio stock.
          </p>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={submitting}
          >
            Cancelar
          </Button>
          <Button
            type="button"
            onClick={() => {
              void onSubmit();
            }}
            disabled={submitting}
            data-testid="promote-confirm"
          >
            {submitting ? <Loader2 className="size-3.5 animate-spin" /> : null}
            Convertir a grupo
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}