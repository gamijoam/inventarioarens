/**
 * EditUserDialog: dialog para editar nombre, correo y roles de un usuario.
 *
 * Backend:
 *   PATCH /api/users/{id}        -> { name, email }
 *   PATCH /api/users/{id}/roles  -> { roles: string[], tenant_id?: number }
 */
import { useEffect, useMemo, useState, type ChangeEvent } from 'react';
import { Save, Search, ShieldCheck, X } from 'lucide-react';
import { toast } from '@/lib/toast';

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

import { useUpdateUser, useUpdateUserRoles, type User } from '../api';
import { useRoles as useAccessRoles } from '@/features/access/api';

interface EditUserDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: User | null;
  onUpdated?: () => void;
}

export function EditUserDialog({ open, onOpenChange, user, onUpdated }: EditUserDialogProps) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [selectedRoles, setSelectedRoles] = useState<Set<string>>(new Set());
  const [initialRoles, setInitialRoles] = useState<Set<string>>(new Set());
  const [roleSearch, setRoleSearch] = useState('');
  const [tenantId, setTenantId] = useState<number | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [emailError, setEmailError] = useState<string | null>(null);

  const currentTenant = useSessionStore((s) => s.tenant ?? null);
  const currentTenantId = currentTenant?.id ?? null;

  const tenantOptions = useMemo(() => {
    const map = new Map<number, { id: number; name: string; slug: string }>();
    if (currentTenant) {
      map.set(currentTenant.id, {
        id: currentTenant.id,
        name: currentTenant.name,
        slug: currentTenant.slug,
      });
    }
    if (user?.tenants) {
      for (const t of user.tenants) {
        map.set(t.id, { id: t.id, name: t.name, slug: t.slug });
      }
    }
    return Array.from(map.values());
  }, [currentTenant, user?.tenants]);

  useEffect(() => {
    if (open && user) {
      setName(user.name);
      setEmail(user.email);
      setError(null);
      setEmailError(null);
      setRoleSearch('');

      const userRoleNames = new Set(user.roles.map((r) => r.name));
      setSelectedRoles(userRoleNames);
      setInitialRoles(userRoleNames);

      const tenantIds = user.tenants?.map((t) => t.id) ?? [];
      if (tenantIds.length > 0) {
        if (currentTenantId && tenantIds.includes(currentTenantId)) {
          setTenantId(currentTenantId);
        } else {
          setTenantId(tenantIds[0] ?? currentTenantId);
        }
      } else {
        setTenantId(currentTenantId);
      }
    }
  }, [open, user, currentTenantId]);

  const update = useUpdateUser();
  const updateRoles = useUpdateUserRoles();

  const { data: rolesData, isLoading: rolesLoading } = useAccessRoles({
    search: '',
    page: 1,
    per_page: 100,
    tenant_id: tenantId ?? undefined,
  });

  const availableRoles = useMemo(() => {
    const list = rolesData?.data ?? [];
    if (!roleSearch.trim()) return list;
    const term = roleSearch.trim().toLowerCase();
    return list.filter((r) => r.name.toLowerCase().includes(term));
  }, [rolesData, roleSearch]);

  function toggleRole(roleName: string) {
    setSelectedRoles((prev) => {
      const next = new Set(prev);
      if (next.has(roleName)) next.delete(roleName);
      else next.add(roleName);
      return next;
    });
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    if (!user) return;

    let hasError = false;
    if (name.trim().length < 1) {
      setError('Requerido.');
      hasError = true;
    } else {
      setError(null);
    }
    if (email.trim().length < 1) {
      setEmailError('Requerido.');
      hasError = true;
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.trim())) {
      setEmailError('Email inválido.');
      hasError = true;
    } else {
      setEmailError(null);
    }

    if (hasError) return;

    setSubmitting(true);
    try {
      await update.mutateAsync({
        id: user.id,
        values: {
          name: name.trim(),
          email: email.trim().toLowerCase(),
        },
      });

      const rolesChanged =
        selectedRoles.size !== initialRoles.size ||
        Array.from(selectedRoles).some((r) => !initialRoles.has(r));

      if (rolesChanged) {
        await updateRoles.mutateAsync({
          id: user.id,
          values: {
            roles: Array.from(selectedRoles),
            tenant_id: tenantId ?? undefined,
          },
        });
      }

      toast.success('Usuario actualizado.');
      onUpdated?.();
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al actualizar.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>Editar usuario</DialogTitle>
          <DialogDescription>
            Modifica los datos personales y roles asignados a este usuario.
          </DialogDescription>
        </DialogHeader>

        {user && (
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="space-y-1.5">
                <Label htmlFor="edit-name">Nombre *</Label>
                <Input
                  id="edit-name"
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  maxLength={150}
                  data-testid="edit-user-name"
                />
                {error && <p className="text-xs text-danger">{error}</p>}
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="edit-email">Correo electrónico *</Label>
                <Input
                  id="edit-email"
                  type="email"
                  value={email}
                  onChange={(e) => setEmail(e.target.value)}
                  maxLength={255}
                  data-testid="edit-user-email"
                />
                {emailError && <p className="text-xs text-danger">{emailError}</p>}
              </div>
            </div>

            {tenantOptions.length > 1 && (
              <div className="space-y-1.5">
                <Label htmlFor="edit-tenant">Empresa para asignación de roles</Label>
                <Select
                  id="edit-tenant"
                  value={tenantId ? String(tenantId) : ''}
                  onChange={(e: ChangeEvent<HTMLSelectElement>) => {
                    const value = e.target.value;
                    setTenantId(value ? Number(value) : null);
                  }}
                  data-testid="edit-user-tenant"
                >
                  {tenantOptions.map((tenant) => (
                    <option key={tenant.id} value={tenant.id}>
                      {tenant.name} ({tenant.slug})
                    </option>
                  ))}
                </Select>
              </div>
            )}

            <div className="space-y-2 border-t border-border pt-3">
              <div className="flex items-center justify-between">
                <Label className="flex items-center gap-1.5 text-sm font-semibold">
                  <ShieldCheck className="size-4 text-primary" />
                  Roles asignados
                </Label>
                {selectedRoles.size > 0 && (
                  <span className="text-xs text-text-muted">
                    {selectedRoles.size} rol(es) seleccionado(s)
                  </span>
                )}
              </div>

              <div className="relative">
                <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-text-muted" />
                <Input
                  value={roleSearch}
                  onChange={(e) => setRoleSearch(e.target.value)}
                  placeholder="Buscar rol por nombre..."
                  className="h-8 pl-8 text-xs"
                  data-testid="edit-user-role-search"
                />
              </div>

              {rolesLoading ? (
                <div className="py-4 text-center text-xs text-text-muted">
                  Cargando roles disponibles...
                </div>
              ) : availableRoles.length === 0 ? (
                <div className="rounded border border-dashed border-border py-4 text-center text-xs text-text-muted">
                  No se encontraron roles disponibles.
                </div>
              ) : (
                <div className="grid max-h-48 grid-cols-1 gap-1.5 overflow-y-auto rounded-lg border border-border bg-bg/40 p-2 sm:grid-cols-2">
                  {availableRoles.map((r) => {
                    const checked = selectedRoles.has(r.name);
                    return (
                      <label
                        key={r.id}
                        className={`flex cursor-pointer items-center gap-2 rounded px-2.5 py-1.5 text-xs transition-colors hover:bg-bg ${
                          checked ? 'bg-primary/10 font-medium text-text-primary' : 'text-text-secondary'
                        }`}
                      >
                        <input
                          type="checkbox"
                          checked={checked}
                          onChange={() => toggleRole(r.name)}
                          className="size-3.5 rounded border-border"
                          data-testid={`edit-role-${r.id}`}
                        />
                        <ShieldCheck className="size-3.5 shrink-0 text-text-muted" aria-hidden="true" />
                        <span className="truncate">{r.name}</span>
                        {r.is_protected && (
                          <span className="ml-auto text-[10px] text-text-muted">Sistema</span>
                        )}
                        {checked && <X className="size-3 shrink-0 text-danger" aria-hidden="true" />}
                      </label>
                    );
                  })}
                </div>
              )}
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
                leftIcon={<Save className="size-4" />}
                data-testid="edit-user-submit"
              >
                Guardar
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}
