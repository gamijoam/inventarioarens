/**
 * ManageDashboardPinDialog
 *
 * Diálogo modal para configurar, cambiar o eliminar el PIN de seguridad del Dashboard.
 */
import { FormEvent, useEffect, useState } from 'react';
import { KeyRound, ShieldAlert, ShieldCheck, Trash2 } from 'lucide-react';

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
import {
  removeDashboardPin,
  setDashboardPin,
  verifyDashboardPin,
} from '../dashboardSecurity';

export interface ManageDashboardPinDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  tenantId?: number | string | null;
  hasPin: boolean;
  onPinSaved: () => void;
  onPinRemoved?: () => void;
}

export function ManageDashboardPinDialog({
  open,
  onOpenChange,
  tenantId,
  hasPin,
  onPinSaved,
  onPinRemoved,
}: ManageDashboardPinDialogProps) {
  const [currentPin, setCurrentPin] = useState('');
  const [newPin, setNewPin] = useState('');
  const [confirmPin, setConfirmPin] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [mode, setMode] = useState<'manage' | 'remove'>('manage');

  useEffect(() => {
    if (open) {
      setCurrentPin('');
      setNewPin('');
      setConfirmPin('');
      setError(null);
      setSuccess(null);
      setMode('manage');
    }
  }, [open]);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);
    setSuccess(null);

    if (mode === 'remove') {
      if (!currentPin.trim()) {
        setError('Debes ingresar tu PIN actual para eliminar la protección.');
        return;
      }
      setBusy(true);
      try {
        const isValid = await verifyDashboardPin(currentPin, tenantId);
        if (!isValid) {
          setError('El PIN actual es incorrecto.');
          setBusy(false);
          return;
        }
        removeDashboardPin(tenantId);
        setSuccess('PIN eliminado exitosamente.');
        setTimeout(() => {
          onPinRemoved?.();
          onOpenChange(false);
        }, 500);
      } catch {
        setError('Ocurrió un error al eliminar el PIN.');
      } finally {
        setBusy(false);
      }
      return;
    }

    // Modo guardar / cambiar PIN
    if (hasPin) {
      if (!currentPin.trim()) {
        setError('Debes ingresar tu PIN actual.');
        return;
      }
    }

    if (newPin.trim().length < 4) {
      setError('El nuevo PIN debe contener al menos 4 dígitos o caracteres.');
      return;
    }

    if (newPin !== confirmPin) {
      setError('La confirmación no coincide con el nuevo PIN.');
      return;
    }

    setBusy(true);
    try {
      if (hasPin) {
        const isValid = await verifyDashboardPin(currentPin, tenantId);
        if (!isValid) {
          setError('El PIN actual es incorrecto.');
          setBusy(false);
          return;
        }
      }

      await setDashboardPin(newPin, tenantId);
      setSuccess(hasPin ? '¡PIN actualizado exitosamente!' : '¡PIN configurado exitosamente!');
      setTimeout(() => {
        onPinSaved();
        onOpenChange(false);
      }, 500);
    } catch {
      setError('Ocurrió un error al guardar el PIN.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-md">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <div className="flex items-center gap-3">
              <div className="flex size-10 items-center justify-center rounded-xl bg-orange-100 text-orange-600">
                <KeyRound className="size-5" />
              </div>
              <div>
                <DialogTitle>
                  {hasPin
                    ? mode === 'remove'
                      ? 'Eliminar PIN de seguridad'
                      : 'Cambiar PIN de seguridad'
                    : 'Configurar PIN de seguridad'}
                </DialogTitle>
                <DialogDescription>
                  {hasPin
                    ? mode === 'remove'
                      ? 'Ingresa tu PIN actual para desactivar la protección del dashboard.'
                      : 'Ingresa tu PIN actual para establecer uno nuevo.'
                    : 'Crea un PIN numérico (4 a 8 dígitos) para ocultar y proteger las cifras del dashboard.'}
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {error && (
              <div className="flex items-center gap-2 rounded-xl bg-rose-50 border border-rose-200 p-3 text-xs text-rose-700 font-medium">
                <ShieldAlert className="size-4 shrink-0 text-rose-600" />
                <span>{error}</span>
              </div>
            )}

            {success && (
              <div className="flex items-center gap-2 rounded-xl bg-emerald-50 border border-emerald-200 p-3 text-xs text-emerald-700 font-medium">
                <ShieldCheck className="size-4 shrink-0 text-emerald-600" />
                <span>{success}</span>
              </div>
            )}

            {hasPin && (
              <div>
                <Label htmlFor="current-pin" className="text-xs font-bold text-slate-600">
                  PIN actual
                </Label>
                <Input
                  id="current-pin"
                  type="password"
                  inputMode="numeric"
                  maxLength={10}
                  autoFocus
                  placeholder="••••"
                  value={currentPin}
                  onChange={(e) => {
                    setCurrentPin(e.target.value);
                    if (error) setError(null);
                  }}
                  className="mt-1 tracking-widest text-center text-lg font-mono rounded-xl"
                />
              </div>
            )}

            {mode === 'manage' && (
              <>
                <div>
                  <Label htmlFor="new-pin" className="text-xs font-bold text-slate-600">
                    {hasPin ? 'Nuevo PIN (mínimo 4 dígitos)' : 'Crear PIN (mínimo 4 dígitos)'}
                  </Label>
                  <Input
                    id="new-pin"
                    type="password"
                    inputMode="numeric"
                    maxLength={10}
                    autoFocus={!hasPin}
                    placeholder="••••"
                    value={newPin}
                    onChange={(e) => {
                      setNewPin(e.target.value);
                      if (error) setError(null);
                    }}
                    className="mt-1 tracking-widest text-center text-lg font-mono rounded-xl"
                  />
                </div>

                <div>
                  <Label htmlFor="confirm-pin" className="text-xs font-bold text-slate-600">
                    Confirmar PIN
                  </Label>
                  <Input
                    id="confirm-pin"
                    type="password"
                    inputMode="numeric"
                    maxLength={10}
                    placeholder="••••"
                    value={confirmPin}
                    onChange={(e) => {
                      setConfirmPin(e.target.value);
                      if (error) setError(null);
                    }}
                    className="mt-1 tracking-widest text-center text-lg font-mono rounded-xl"
                  />
                </div>
              </>
            )}

            {hasPin && (
              <div className="pt-2 flex justify-end">
                {mode === 'manage' ? (
                  <button
                    type="button"
                    onClick={() => {
                      setMode('remove');
                      setError(null);
                    }}
                    className="text-xs text-rose-600 hover:text-rose-700 font-medium inline-flex items-center gap-1 transition-colors"
                  >
                    <Trash2 className="size-3.5" />
                    <span>Eliminar protección por PIN</span>
                  </button>
                ) : (
                  <button
                    type="button"
                    onClick={() => {
                      setMode('manage');
                      setError(null);
                    }}
                    className="text-xs text-slate-600 hover:text-slate-800 font-medium transition-colors"
                  >
                    Volver a cambiar PIN
                  </button>
                )}
              </div>
            )}
          </div>

          <DialogFooter className="gap-2 sm:gap-0">
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              className="rounded-xl"
            >
              Cancelar
            </Button>
            <Button
              type="submit"
              disabled={busy}
              className={`rounded-xl text-white ${
                mode === 'remove'
                  ? 'bg-rose-600 hover:bg-rose-700'
                  : 'bg-orange-600 hover:bg-orange-700'
              }`}
            >
              {busy
                ? 'Guardando...'
                : mode === 'remove'
                ? 'Eliminar PIN'
                : hasPin
                ? 'Actualizar PIN'
                : 'Guardar PIN'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
