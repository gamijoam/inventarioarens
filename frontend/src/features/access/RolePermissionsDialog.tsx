/**
 * RolePermissionsDialog: dialog para ver y editar los permisos de un
 * rol (vista en arbol jerarquico, marcar/desmarcar).
 *
 * Backend: PATCH /api/roles/{id}/permissions
 *   Body: { permissions: string[] }   (reemplaza TODOS).
 *
 * Roles base (is_protected) tambien pueden editar permisos desde aqui
 * (el backend lo permite para personalizar). El nombre no se puede editar.
 */
import { useEffect, useState } from 'react';
import { Save } from 'lucide-react';
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
import { Skeleton } from '@/components/ui/Skeleton';

import { useRole, useRolePreview, useUpdateRolePermissions, usePermissionCatalog, type Role } from './api';
import { ProtectedRoleBadge } from './ProtectedRoleBadge';
import { PermissionTree } from './PermissionTree';

interface RolePermissionsDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  role: Role | null;
  onSaved?: (role: Role) => void;
}

export function RolePermissionsDialog({ open, onOpenChange, role, onSaved }: RolePermissionsDialogProps) {
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [submitting, setSubmitting] = useState(false);

  const { data: fullRole, isLoading: roleLoading } = useRole(role?.id ?? 0);
  const { data: preview } = useRolePreview(role?.id ?? 0);
  const { data: catalog, isLoading: catalogLoading } = usePermissionCatalog();
  const update = useUpdateRolePermissions();

  // Cargar permisos actuales cuando se abre o cambia el rol.
  useEffect(() => {
    if (!open || !fullRole) return;
    const perms = fullRole.permissions ?? [];
    setSelected(new Set(perms));
  }, [open, fullRole]);

  function toggle(permission: string, checked: boolean) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (checked) next.add(permission);
      else next.delete(permission);
      return next;
    });
  }

  async function handleSave() {
    if (!role) return;
    setSubmitting(true);
    try {
      const updated = await update.mutateAsync({
        id: role.id,
        values: { permissions: Array.from(selected) },
      });
      toast.success(`Permisos actualizados: ${updated.permissions?.length ?? 0}.`);
      onSaved?.(updated);
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al guardar permisos.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  if (!role) return null;

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <DialogTitle>Permisos: {role.name}</DialogTitle>
            {role.is_protected && <ProtectedRoleBadge isProtected />}
          </div>
          <DialogDescription>
            Marca los permisos que tendra este rol. Los cambios reemplazan la
            lista completa.
            {preview?.data && (
              <span className="ml-2 text-text-muted">
                {preview.data.permission_count} permisos en{' '}
                {preview.data.module_count} modulos.
              </span>
            )}
          </DialogDescription>
        </DialogHeader>

        {roleLoading || catalogLoading ? (
          <Skeleton className="h-64 w-full" />
        ) : (
          <PermissionTree
            modules={catalog?.modules ?? []}
            selected={selected}
            onToggle={toggle}
          />
        )}

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
            onClick={handleSave}
            loading={submitting}
            leftIcon={<Save className="size-4" />}
            data-testid="role-permissions-save"
          >
            Guardar permisos
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}