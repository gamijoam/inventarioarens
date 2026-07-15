/**
 * DuplicateRoleDialog: dialog para clonar un rol existente (base o custom)
 * en uno nuevo con un nombre distinto.
 *
 * Backend: POST /api/roles/{id}/duplicate
 *   Body: { name: string }
 *   Response: 201 + Role nuevo (con tenant_id del actual, sin permisos
 *   adicionales mas alla de los del source).
 */
import { useEffect, useState } from 'react';
import { Copy, Plus } from 'lucide-react';
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
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';

import { useDuplicateRole, type Role } from './api';

interface DuplicateRoleDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  sourceRole: Role | null;
  onCloned?: (role: Role) => void;
}

export function DuplicateRoleDialog({ open, onOpenChange, sourceRole, onCloned }: DuplicateRoleDialogProps) {
  const [name, setName] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open && sourceRole) {
      setName(`${sourceRole.name} (copia)`);
      setError(null);
    }
  }, [open, sourceRole]);

  const duplicate = useDuplicateRole();

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!sourceRole) return;
    if (name.trim().length < 1) {
      setError('Requerido.');
      return;
    }
    setSubmitting(true);
    try {
      const cloned = await duplicate.mutateAsync({ id: sourceRole.id, values: { name: name.trim() } });
      toast.success(`Rol duplicado: ${cloned.name}.`);
      onCloned?.(cloned);
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al duplicar el rol.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>Duplicar rol</DialogTitle>
          <DialogDescription>
            {sourceRole && (
              <>
                Crea una copia de <strong>{sourceRole.name}</strong> con todos
                sus permisos. El nuevo rol es editable: podes cambiarle el
                nombre y los permisos despues.
              </>
            )}
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-1.5">
            <Label htmlFor="duplicate-name">Nombre del nuevo rol *</Label>
            <Input
              id="duplicate-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              maxLength={150}
              data-testid="duplicate-role-name"
            />
            {error && <p className="text-xs text-danger">{error}</p>}
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
              type="submit"
              loading={submitting}
              leftIcon={<Plus className="size-4" />}
              data-testid="duplicate-role-submit"
            >
              <Copy className="mr-1 size-4" aria-hidden="true" /> Duplicar
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}