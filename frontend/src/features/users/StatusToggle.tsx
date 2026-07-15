/**
 * StatusToggle: boton que activa/inactiva un usuario. Pide confirmacion
 * tipeando el email del usuario antes de aplicar el cambio.
 *
 * Usa el ConfirmDestructiveDialog compartido (Fase B).
 *
 * Backend: PATCH /api/users/{id}/status
 *   Body: { status: 'active' | 'inactive' }
 *
 * El backend tiene protecciones:
 *   - No podes inactivar tu propio usuario.
 *   - No podes inactivar al ultimo admin activo del tenant.
 */
import { useState } from 'react';
import { Power } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { ConfirmDestructiveDialog } from '@/components/ConfirmDestructiveDialog';

import { useUpdateUserStatus, type User } from './api';

interface StatusToggleProps {
  user: User;
  canEdit: boolean;
}

export function StatusToggle({ user, canEdit }: StatusToggleProps) {
  const [open, setOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const update = useUpdateUserStatus();

  const isActive = user.status === 'active';
  // Para activar: dangerLevel medium (no necesita tipeo).
  // Para inactivar: dangerLevel high (pide tipear email).
  const needsTypeConfirm = !isActive;

  async function handleConfirm() {
    setSubmitting(true);
    try {
      await update.mutateAsync({
        id: user.id,
        values: { status: isActive ? 'inactive' : 'active' },
      });
      toast.success(isActive ? 'Usuario inactivado.' : 'Usuario reactivado.');
      setOpen(false);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al cambiar estado.';
      toast.error(msg);
    } finally {
      setSubmitting(false);
    }
  }

  if (!canEdit) return null;

  return (
    <>
      <Button
        size="icon-sm"
        variant="ghost"
        onClick={() => setOpen(true)}
        title={isActive ? 'Inactivar usuario' : 'Reactivar usuario'}
        aria-label={isActive ? 'Inactivar usuario' : 'Reactivar usuario'}
        data-testid={`status-toggle-${user.id}`}
      >
        <Power
          className={`size-4 ${isActive ? 'text-warning' : 'text-success'}`}
          aria-hidden="true"
        />
      </Button>

      {needsTypeConfirm ? (
        <ConfirmDestructiveDialog
          open={open}
          onOpenChange={setOpen}
          title="Inactivar usuario"
          description={
            <>
              <p>
                Al inactivar a <strong>{user.name}</strong> ya no podra iniciar
                sesion ni operar en esta empresa.
              </p>
              <p className="mt-2">
                Para reactivarlo, vuelve a marcarlo como activo desde este dialog.
              </p>
            </>
          }
          confirmText={user.email}
          inputHint="Escribe el email del usuario"
          confirmLabel="Inactivar"
          onConfirm={handleConfirm}
          loading={submitting}
          dangerLevel="high"
        />
      ) : (
        <SimpleConfirm
          open={open}
          onOpenChange={setOpen}
          user={user}
          onConfirm={handleConfirm}
          loading={submitting}
        />
      )}
    </>
  );
}

/**
 * Confirm simple (sin tipeo) para reactivar usuario. Es la operacion
 * inversa de inactivar y es de bajo riesgo.
 */
function SimpleConfirm({
  open,
  onOpenChange,
  user,
  onConfirm,
  loading,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  user: User;
  onConfirm: () => void | Promise<void>;
  loading: boolean;
}) {
  return (
    <ConfirmDestructiveDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Reactivar usuario"
      description={
        <>
          <p>
            Vas a reactivar a <strong>{user.name}</strong>. Podra volver a iniciar
            sesion y operar en esta empresa.
          </p>
        </>
      }
      confirmText={user.email}
      inputHint="Escribe el email del usuario"
      confirmLabel="Reactivar"
      onConfirm={onConfirm}
      loading={loading}
      dangerLevel="medium"
    />
  );
}