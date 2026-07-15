/**
 * CreateUserDialog: dialog para crear un usuario y vincularlo al tenant
 * actual con los roles seleccionados.
 *
 * Backend: POST /api/users
 *   Body: { name, email, password?, roles[] }
 *
 * La password es opcional: si el admin no la setea, el backend genera
 * una aleatoria que el user deberia cambiar en su primer login
 * (worklow futuro: magic link / reset password).
 */
import { useEffect, useState } from 'react';
import { Plus } from 'lucide-react';
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
import { Skeleton } from '@/components/ui/Skeleton';

import { useCreateUser } from '@/features/users/api';
import { useRoles as useAccessRoles } from '@/features/access/api';

interface CreateUserDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onCreated?: (userId: number) => void;
}

export function CreateUserDialog({ open, onOpenChange, onCreated }: CreateUserDialogProps) {
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [selectedRoles, setSelectedRoles] = useState<Set<string>>(new Set());
  const [submitting, setSubmitting] = useState(false);
  const [errors, setErrors] = useState<Record<string, string>>({});

  // Resetear form cada vez que se abre.
  useEffect(() => {
    if (open) {
      setName('');
      setEmail('');
      setPassword('');
      setSelectedRoles(new Set());
      setErrors({});
    }
  }, [open]);

  const { data: rolesData, isLoading: rolesLoading } = useAccessRoles();
  const create = useCreateUser();

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
    const errs: Record<string, string> = {};
    if (name.trim().length < 1) errs.name = 'Requerido.';
    if (!email.includes('@')) errs.email = 'Email invalido.';
    if (password && password.length < 8) errs.password = 'Minimo 8 caracteres.';
    if (Object.keys(errs).length > 0) {
      setErrors(errs);
      return;
    }

    setSubmitting(true);
    try {
      const user = await create.mutateAsync({
        name: name.trim(),
        email: email.trim().toLowerCase(),
        password: password || undefined,
        roles: Array.from(selectedRoles),
      });
      toast.success('Usuario creado.');
      onCreated?.(user.id);
      onOpenChange(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al crear el usuario.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>Nuevo usuario</DialogTitle>
          <DialogDescription>
            Crea un usuario y asignalos uno o varios roles en esta empresa.
            Si no asignas una contrasena, el sistema generara una aleatoria.
          </DialogDescription>
        </DialogHeader>

        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div className="space-y-1.5">
              <Label htmlFor="create-name">Nombre *</Label>
              <Input
                id="create-name"
                value={name}
                onChange={(e) => setName(e.target.value)}
                maxLength={150}
                data-testid="create-user-name"
              />
              {errors.name && <p className="text-xs text-danger">{errors.name}</p>}
            </div>
            <div className="space-y-1.5">
              <Label htmlFor="create-email">Email *</Label>
              <Input
                id="create-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                maxLength={255}
                data-testid="create-user-email"
              />
              {errors.email && <p className="text-xs text-danger">{errors.email}</p>}
            </div>
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="create-password">Contrasena (opcional)</Label>
            <Input
              id="create-password"
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              minLength={8}
              placeholder="Si la dejas vacia, se genera una aleatoria"
              data-testid="create-user-password"
            />
            {errors.password && <p className="text-xs text-danger">{errors.password}</p>}
          </div>

          <div className="space-y-1.5">
            <Label>Roles</Label>
            {rolesLoading ? (
              <Skeleton className="h-16 w-full" />
            ) : (
              <div className="grid max-h-48 grid-cols-1 gap-1 overflow-y-auto rounded border border-border bg-bg/30 p-2 sm:grid-cols-2">
                {(rolesData?.data ?? []).map((r) => (
                  <label
                    key={r.id}
                    className="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-bg/60"
                  >
                    <input
                      type="checkbox"
                      checked={selectedRoles.has(r.name)}
                      onChange={() => toggleRole(r.name)}
                      data-testid={`create-user-role-${r.id}`}
                    />
                    <span className="truncate">{r.name}</span>
                    {r.is_protected && (
                      <span className="ml-auto text-[10px] text-text-muted">Protegido</span>
                    )}
                  </label>
                ))}
              </div>
            )}
            <p className="text-xs text-text-muted">
              {selectedRoles.size === 0
                ? 'Sin roles: el usuario no podra hacer nada hasta que le asignes uno.'
                : `${selectedRoles.size} rol(es) seleccionado(s).`}
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
              type="submit"
              loading={submitting}
              leftIcon={<Plus className="size-4" />}
              data-testid="create-user-submit"
            >
              Crear usuario
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}