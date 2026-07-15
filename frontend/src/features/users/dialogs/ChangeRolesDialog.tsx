/**
 * ChangeRolesDialog: dialog para asignar/reemplazar los roles de un
 * usuario en el tenant actual.
 *
 * Backend: PATCH /api/users/{id}/roles
 *   Body: { roles: string[] }  (reemplaza TODOS los roles; no es aditivo).
 *
 * El backend valida que los roles existan en el tenant. Si el usuario
 * actual es el unico admin activo, el backend rechaza quitarle el rol
 * admin (proteccion contra bloqueo).
 */
import { useEffect, useMemo, useState, type ChangeEvent } from 'react';
import { ShieldCheck, X } from 'lucide-react';
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

import { useUpdateUserRoles, type User } from '../api';
import { useRoles as useAccessRoles } from '@/features/access/api';

interface ChangeRolesDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: User | null;
  onUpdated?: () => void;
}

export function ChangeRolesDialog({ open, onOpenChange, user, onUpdated }: ChangeRolesDialogProps) {
  const [search, setSearch] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (open && user) {
      setSearch('');
      setSelected(new Set(user.roles.map((r) => r.name)));
    }
  }, [open, user]);

  const update = useUpdateUserRoles();
  const { data: rolesData, isLoading: rolesLoading } = useAccessRoles();

  const availableRoles = useMemo(() => {
    const list = rolesData?.data ?? [];
    if (!search.trim()) return list;
    const term = search.trim().toLowerCase();
    return list.filter((r) => r.name.toLowerCase().includes(term));
  }, [rolesData, search]);

  function toggle(roleName: string) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(roleName)) next.delete(roleName);
      else next.add(roleName);
      return next;
    });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;
    setSubmitting(true);
    try {
      await update.mutateAsync({
        id: user.id,
        values: { roles: Array.from(selected) },
      });
      toast.success('Roles actualizados.');
      onUpdated?.();
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al actualizar roles.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Cambiar roles</DialogTitle>
          <DialogDescription>
            {user && (
              <>
                Asigna los roles de <strong>{user.name}</strong> en esta empresa.
                Si quitas todos los roles, no podra hacer nada.
              </>
            )}
          </DialogDescription>
        </DialogHeader>

        {user && (
          <form onSubmit={handleSubmit} className="space-y-3">
            <Input
              value={search}
              onChange={(e: ChangeEvent<HTMLInputElement>) => setSearch(e.target.value)}
              placeholder="Buscar rol por nombre..."
              data-testid="change-roles-search"
            />

            {rolesLoading ? (
              <div className="text-sm text-text-muted">Cargando roles...</div>
            ) : (
              <div className="grid max-h-80 grid-cols-1 gap-1 overflow-y-auto rounded border border-border bg-bg/30 p-2 sm:grid-cols-2">
                {availableRoles.length === 0 ? (
                  <div className="col-span-full px-2 py-3 text-sm text-text-muted">
                    Sin resultados.
                  </div>
                ) : (
                  availableRoles.map((r) => {
                    const checked = selected.has(r.name);
                    return (
                      <label
                        key={r.id}
                        className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-bg/60"
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => toggle(r.name)}
                          data-testid={`change-role-${r.id}`}
                        />
                        <ShieldCheck className="size-3.5 text-text-muted" aria-hidden="true" />
                        <span className="truncate">{r.name}</span>
                        {r.is_protected && (
                          <span className="ml-auto text-[10px] text-text-muted">Protegido</span>
                        )}
                        {checked && <X className="size-3 text-danger" aria-hidden="true" />}
                      </label>
                    );
                  })
                )}
              </div>
            )}

            <p className="text-xs text-text-muted">
              {selected.size === 0
                ? 'Sin roles seleccionados.'
                : `${selected.size} rol(es) seleccionado(s): ${Array.from(selected).join(', ')}`}
            </p>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
                disabled={submitting}
              >
                Cancelar
              </Button>
              <Button type="submit" loading={submitting} data-testid="change-roles-submit">
                Guardar roles
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}