/**
 * UnlockDashboardPinDialog
 *
 * Diálogo modal para solicitar el PIN de seguridad antes de desocultar
 * las cifras y tarjetas del Dashboard.
 */
import { FormEvent, useState } from 'react';
import { Lock } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Button } from '@/components/ui/Button';
import { Label } from '@/components/ui/Label';
import { verifyDashboardPin } from '../dashboardSecurity';

export interface UnlockDashboardPinDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tenantId?: number | string | null;
  onSuccess: () => void;
}

export function UnlockDashboardPinDialog({
  open,
  onOpenChange,
  tenantId,
  onSuccess,
}: UnlockDashboardPinDialogProps) {
  const [pin, setPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    if (!pin.trim()) {
      setError('Por favor ingresa tu PIN de seguridad.');
      return;
    }

    setBusy(true);
    setError(null);
    try {
      const isValid = await verifyDashboardPin(pin, tenantId);
      if (isValid) {
        setPin('');
        setError(null);
        onSuccess();
        onOpenChange(false);
      } else {
        setError('El PIN introducido es incorrecto.');
        setPin('');
      }
    } catch {
      setError('Ocurrió un error al verificar el PIN.');
    } finally {
      setBusy(false);
    }
  };

  const handleClose = () => {
    setPin('');
    setError(null);
    onOpenChange(false);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-sm">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-3">
              <div className="flex size-10 items-center justify-center rounded-xl bg-orange-100 text-orange-600">
                <Lock className="size-5" />
              </div>
              <div>
                <DialogTitle>Desbloquear Dashboard</DialogTitle>
                <DialogDescription>
                  Ingresa tu PIN de seguridad para mostrar las cifras y métricas.
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          <div className="space-y-4 py-4">
            <div>
              <Label htmlFor="unlock-pin" className="text-xs font-bold text-slate-600">
                PIN de seguridad
              </Label>
              <div className="relative mt-1">
                <Input
                  id="unlock-pin"
                  type="password"
                  inputMode="numeric"
                  autoFocus
                  maxLength={10}
                  placeholder="••••"
                  value={pin}
                  onChange={(e) => {
                    setPin(e.target.value);
                    if (error) setError(null);
                  }}
                  className="tracking-widest text-center text-lg font-mono rounded-xl"
                  invalid={Boolean(error)}
                />
              </div>
              {error && (
                <p className="mt-1.5 text-xs text-rose-600 font-medium">{error}</p>
              )}
            </div>
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={handleClose}
              className="rounded-xl"
            >
              Cancelar
            </Button>
            <Button
              type="submit"
              disabled={busy || !pin.trim()}
              className="rounded-xl bg-orange-600 hover:bg-orange-700 text-white"
            >
              {busy ? 'Verificando...' : 'Desbloquear'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
