/**
 * ConfirmDestructiveDialog: dialog de confirmacion para acciones
 * destructivas. El usuario debe tipear el `confirmText` (por defecto el
 * nombre del recurso) para habilitar el boton de confirmar.
 *
 * Patron inspirado en GitHub/GitLab: previene clicks accidentales en
 * eliminar/desactivar/cambiar roles de admin/etc.
 *
 * Uso:
 *   <ConfirmDestructiveDialog
 *     open={open}
 *     onOpenChange={setOpen}
 *     title="Eliminar usuario"
 *     description="Esta accion no se puede deshacer."
 *     confirmText={user.name}
 *     onConfirm={async () => { await deleteUser(); }}
 *     dangerLevel="high"
 *   />
 */
import { useEffect, useState, type ReactNode } from 'react';
import { AlertTriangle, Trash2 } from 'lucide-react';

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
import { cn } from '@/lib/cn';

type DangerLevel = 'medium' | 'high';

interface ConfirmDestructiveDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: ReactNode;
  confirmText: string;
  onConfirm: () => void | Promise<void>;
  // Texto del boton (default "Eliminar").
  confirmLabel?: string;
  // Texto del input hint que muestra al usuario que tiene que tipear.
  inputHint?: string;
  // medium: warning amarillo. high: red critico (default).
  dangerLevel?: DangerLevel;
  // Loading mientras se ejecuta onConfirm.
  loading?: boolean;
}

export function ConfirmDestructiveDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmText,
  onConfirm,
  confirmLabel = 'Eliminar',
  inputHint,
  dangerLevel = 'high',
  loading = false,
}: ConfirmDestructiveDialogProps) {
  const [typed, setTyped] = useState('');

  // Resetear el input cada vez que se abre el dialog.
  useEffect(() => {
    if (open) setTyped('');
  }, [open]);

  const isReady = typed === confirmText;

  async function handleConfirm() {
    if (!isReady) return;
    await onConfirm();
  }

  const isHigh = dangerLevel === 'high';
  const Icon = isHigh ? Trash2 : AlertTriangle;
  const iconClass = isHigh ? 'text-danger' : 'text-warning';

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <div className="flex items-start gap-3">
            <Icon className={cn('mt-0.5 size-5 shrink-0', iconClass)} aria-hidden="true" />
            <div className="min-w-0">
              <DialogTitle>{title}</DialogTitle>
              <DialogDescription className="mt-1.5">{description}</DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <div className="mt-2 space-y-2">
          <p className="text-sm text-text-secondary">
            Para confirmar, escribe{' '}
            <code className="rounded bg-bg px-1.5 py-0.5 font-mono text-sm font-semibold">
              {confirmText}
            </code>{' '}
            abajo.
          </p>
          <Input
            value={typed}
            onChange={(e) => setTyped(e.target.value)}
            placeholder={inputHint ?? confirmText}
            autoComplete="off"
            autoCorrect="off"
            spellCheck={false}
            data-testid="confirm-destructive-input"
          />
        </div>

        <DialogFooter className="mt-2">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={loading}
          >
            Cancelar
          </Button>
          <Button
            type="button"
            variant={isHigh ? 'danger' : 'outline'}
            onClick={handleConfirm}
            disabled={!isReady || loading}
            loading={loading}
            data-testid="confirm-destructive-submit"
          >
            {confirmLabel}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}