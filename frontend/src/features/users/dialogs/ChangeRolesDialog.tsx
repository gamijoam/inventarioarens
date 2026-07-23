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
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { useSessionStore } from '@/stores/session';

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
  const [tenantSearch, setTenantSearch] = useState('');
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [tenantId, setTenantId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const currentTenantId = useSessionStore((s) => s.tenant?.id ?? null);

  useEffect(() => {
    if (open && user) {
      setSearch('');
      setTenantSearch('');
      setSelected(new Set(user.roles.map((r) => r.name)));
      const tenantIds = user.tenants?.map((tenant) => tenant.id) ?? [];
      if (tenantIds.length > 0) {
        setTenantId((prev) => {
          if (prev && tenantIds.includes(prev)) return prev;
          if (currentTenantId && tenantIds.includes(currentTenantId)) return currentTenantId;
          return tenantIds[0] ?? null;
        });
      } else {
        setTenantId(currentTenantId);
      }
    }
  }, [open, user, currentTenantId]);

  const update = useUpdateUserRoles();
  const { data: rolesData, isLoading: rolesLoading } = useAccessRoles({
    search: '',
    page: 1,
    per_page: 100,
    tenant_id: tenantId ?? undefined,
  });

  const tenantOptions = user?.tenants ?? [];
  const filteredTenants = useMemo(() => {
    const term = tenantSearch.trim().toLowerCase();
    const filtered = !term
      ? tenantOptions
      : tenantOptions.filter((tenant) => {
      return tenant.name.toLowerCase().includes(term) || tenant.slug.toLowerCase().includes(term);
    });
    if (tenantId && !filtered.some((tenant) => tenant.id === tenantId)) {
      const selectedTenant = tenantOptions.find((tenant) => tenant.id === tenantId);
      return selectedTenant ? [selectedTenant, ...filtered] : filtered;
    }
    return filtered;
  }, [tenantId, tenantOptions, tenantSearch]);

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
        values: { roles: Array.from(selected), tenant_id: tenantId ?? undefined },
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

            {tenantOptions.length > 1 && (
              <div className="space-y-1">
                <Label>Empresa destino</Label>
                <Input
                  value={tenantSearch}
                  onChange={(e: ChangeEvent<HTMLInputElement>) => setTenantSearch(e.target.value)}
                  placeholder="Buscar empresa..."
                  data-testid="change-roles-tenant-search"
                />
                <Select
                  value={tenantId ? String(tenantId) : ''}
                  onChange={(e: ChangeEvent<HTMLSelectElement>) => {
                    const value = e.target.value;
                    setTenantId(value ? Number(value) : null);
                  }}
                  data-testid="change-roles-tenant"
                >
                  {filteredTenants.map((tenant) => (
                    <option key={tenant.id} value={tenant.id}>
                      {tenant.name} ({tenant.slug})
                    </option>
                  ))}
                </Select>
                <p className="text-xs text-text-muted">
                  {filteredTenants.length === 0
                    ? 'Sin empresas que coincidan.'
                    : `${filteredTenants.length} empresa(s) disponibles.`}
                </p>
              </div>
            )}

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
