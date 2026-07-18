import { useMemo, useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Banknote, Building2, Loader2, Plus, Store } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Input } from '@/components/ui/Input';
import { Select } from '@/components/ui/Select';
import { PageLayout } from '@/components/layout/PageLayout';
import { Can } from '@/components/permissions/Can';
import { PERMISSIONS } from '@/permissions/constants';
import { useCan } from '@/permissions/useCan';
import {
  type CashRegisterSession,
  useAddCashMovement,
  useBranchesForPos,
  useCashRegisters,
  useCashSessions,
  useCloseCashSession,
  useCreateCashRegister,
  useCreatePosBranch,
  useOpenCashSession,
} from './api';

export function CashRegisterSetup() {
  const { data: branches = [], isLoading: loadingBranches } = useBranchesForPos();
  const { data: registers = [], isLoading: loadingRegisters } = useCashRegisters();
  const { data: sessions = [], isLoading: loadingSessions } = useCashSessions();
  const canOpen = useCan(PERMISSIONS.CASH_REGISTER_OPEN);
  const canMove = useCan(PERMISSIONS.CASH_REGISTER_MOVE) || useCan(PERMISSIONS.CASH_REGISTER_MOVEMENTS);
  const canClose = useCan(PERMISSIONS.CASH_REGISTER_CLOSE);
  const createBranch = useCreatePosBranch();
  const createRegister = useCreateCashRegister();
  const openSession = useOpenCashSession();
  const addMovement = useAddCashMovement();
  const closeSession = useCloseCashSession();
  const [branchForm, setBranchForm] = useState({ name: '', code: '' });
  const [registerForm, setRegisterForm] = useState({ name: '', code: '', branch_id: '' });
  const [openForm, setOpenForm] = useState({ branch_id: '', cash_register_id: '', opening_amount: '0' });
  const [movementForm, setMovementForm] = useState({ type: 'outflow', amount: '', notes: '' });
  const [closingAmount, setClosingAmount] = useState('');

  const branchOptions = useMemo(
    () => branches.filter((branch) => (branch.status ?? 'active') === 'active'),
    [branches],
  );
  const registerOptions = useMemo(
    () => registers.filter((register) => (register.status ?? 'active') === 'active'),
    [registers],
  );
  const activeSession = sessions.find((session) => session.status === 'open' && Boolean(session.cash_register_id)) ?? null;

  return (
    <PageLayout
      title="Cajas"
      description="Configura sucursales y cajas fisicas antes de abrir un turno POS."
      actions={
        <Button asChild>
          <Link to="/pos">
            <Banknote className="size-4" /> Ir al POS
          </Link>
        </Button>
      }
    >
      <div className="space-y-4">
        <CashSessionCard
          session={activeSession}
          loading={loadingSessions}
          branches={branchOptions}
          registers={registerOptions}
          canOpen={canOpen}
          canMove={canMove}
          canClose={canClose}
          openForm={openForm}
          movementForm={movementForm}
          closingAmount={closingAmount}
          opening={openSession.isPending}
          moving={addMovement.isPending}
          closing={closeSession.isPending}
          onOpenForm={setOpenForm}
          onMovementForm={setMovementForm}
          onClosingAmount={setClosingAmount}
          onOpen={() => {
            if (!openForm.branch_id || !openForm.cash_register_id) {
              toast.error('Selecciona sucursal y caja fisica activa.');
              return;
            }
            openSession.mutate({
              branch_id: Number(openForm.branch_id),
              cash_register_id: Number(openForm.cash_register_id),
              opening_currency: 'USD',
              opening_amount: Number(openForm.opening_amount || 0),
              notes: 'Apertura desde modulo Cajas',
            }, {
              onSuccess: () => setOpenForm({ branch_id: '', cash_register_id: '', opening_amount: '0' }),
            });
          }}
          onMovement={() => {
            if (!activeSession) return;
            if (!movementForm.amount || Number(movementForm.amount) <= 0) {
              toast.error('Indica un monto valido.');
              return;
            }
            addMovement.mutate({
              sessionId: activeSession.id,
              payload: {
                type: movementForm.type as 'inflow' | 'outflow' | 'adjustment',
                method: 'cash',
                currency: 'USD',
                amount: Number(movementForm.amount),
                notes: movementForm.notes || null,
              },
            }, {
              onSuccess: () => setMovementForm({ type: 'outflow', amount: '', notes: '' }),
            });
          }}
          onClose={() => {
            if (!activeSession) return;
            closeSession.mutate({
              sessionId: activeSession.id,
              payload: {
                counted_currency: 'USD',
                counted_amount: Number(closingAmount || 0),
                closing_notes: 'Cierre desde modulo Cajas',
              },
            }, {
              onSuccess: () => setClosingAmount(''),
            });
          }}
        />

        <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Building2 className="size-4" /> Sucursales
            </CardTitle>
            <CardDescription>Una caja fisica siempre pertenece a una sucursal.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <Can I={PERMISSIONS.BRANCHES_CREATE}>
              <div className="grid gap-2 rounded border border-border bg-bg/40 p-3 sm:grid-cols-[1fr_120px_auto]">
                <Input
                  value={branchForm.name}
                  onChange={(event) => setBranchForm((current) => ({ ...current, name: event.target.value }))}
                  placeholder="Nombre de sucursal"
                />
                <Input
                  value={branchForm.code}
                  onChange={(event) => setBranchForm((current) => ({ ...current, code: event.target.value }))}
                  placeholder="Codigo"
                />
                <Button
                  disabled={createBranch.isPending}
                  onClick={() => {
                    if (!branchForm.name.trim() || !branchForm.code.trim()) {
                      toast.error('Indica nombre y codigo de la sucursal.');
                      return;
                    }
                    createBranch.mutate({
                      name: branchForm.name.trim(),
                      code: branchForm.code.trim().toUpperCase(),
                      status: 'active',
                    }, {
                      onSuccess: () => setBranchForm({ name: '', code: '' }),
                    });
                  }}
                >
                  {createBranch.isPending ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
                  Crear
                </Button>
              </div>
            </Can>

            {loadingBranches ? (
              <LoadingLine label="Cargando sucursales..." />
            ) : branches.length === 0 ? (
              <EmptySetup text="No hay sucursales configuradas para esta empresa." />
            ) : (
              <div className="divide-y divide-border rounded border border-border">
                {branches.map((branch) => (
                  <div key={branch.id} className="flex items-center justify-between gap-3 p-3">
                    <div>
                      <p className="font-medium">{branch.name}</p>
                      <p className="font-mono text-xs text-text-muted">{branch.code}</p>
                    </div>
                    <Badge variant={(branch.status ?? 'active') === 'active' ? 'success' : 'default'}>
                      {branch.status ?? 'active'}
                    </Badge>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Store className="size-4" /> Cajas fisicas
            </CardTitle>
            <CardDescription>Estas son las cajas que el cajero puede abrir desde POS.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <Can I={PERMISSIONS.CASH_REGISTER_CREATE}>
              <div className="grid gap-2 rounded border border-border bg-bg/40 p-3">
                <div className="grid gap-2 sm:grid-cols-[1fr_120px]">
                  <Input
                    value={registerForm.name}
                    onChange={(event) => setRegisterForm((current) => ({ ...current, name: event.target.value }))}
                    placeholder="Nombre de caja"
                  />
                  <Input
                    value={registerForm.code}
                    onChange={(event) => setRegisterForm((current) => ({ ...current, code: event.target.value }))}
                    placeholder="Codigo"
                  />
                </div>
                <Select
                  value={registerForm.branch_id}
                  onChange={(event) => setRegisterForm((current) => ({ ...current, branch_id: event.target.value }))}
                >
                  <option value="">Sucursal...</option>
                  {branchOptions.map((branch) => (
                    <option key={branch.id} value={branch.id}>
                      {branch.code} - {branch.name}
                    </option>
                  ))}
                </Select>
                <Button
                  disabled={createRegister.isPending || branchOptions.length === 0}
                  onClick={() => {
                    if (!registerForm.name.trim() || !registerForm.code.trim() || !registerForm.branch_id) {
                      toast.error('Indica nombre, codigo y sucursal de la caja.');
                      return;
                    }
                    createRegister.mutate({
                      name: registerForm.name.trim(),
                      code: registerForm.code.trim().toUpperCase(),
                      branch_id: Number(registerForm.branch_id),
                      status: 'active',
                    }, {
                      onSuccess: () => setRegisterForm({ name: '', code: '', branch_id: '' }),
                    });
                  }}
                >
                  {createRegister.isPending ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
                  Crear caja
                </Button>
              </div>
            </Can>

            {loadingRegisters ? (
              <LoadingLine label="Cargando cajas..." />
            ) : registers.length === 0 ? (
              <EmptySetup text="No hay cajas fisicas configuradas." />
            ) : (
              <div className="divide-y divide-border rounded border border-border">
                {registers.map((register) => (
                  <div key={register.id} className="flex items-center justify-between gap-3 p-3">
                    <div>
                      <p className="font-medium">{register.name}</p>
                      <p className="font-mono text-xs text-text-muted">{register.code ?? register.id}</p>
                    </div>
                    <div className="text-right">
                      <Badge variant={(register.status ?? 'active') === 'active' ? 'success' : 'default'}>
                        {register.status ?? 'active'}
                      </Badge>
                      {Boolean(register.open_session) && <p className="mt-1 text-xs text-success">Turno abierto</p>}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
        </div>
      </div>
    </PageLayout>
  );
}

function CashSessionCard({
  session,
  loading,
  branches,
  registers,
  canOpen,
  canMove,
  canClose,
  openForm,
  movementForm,
  closingAmount,
  opening,
  moving,
  closing,
  onOpenForm,
  onMovementForm,
  onClosingAmount,
  onOpen,
  onMovement,
  onClose,
}: {
  session: CashRegisterSession | null;
  loading: boolean;
  branches: Array<{ id: number; name: string; code: string }>;
  registers: Array<{ id: number; name: string; code?: string | null; branch_id?: number | null }>;
  canOpen: boolean;
  canMove: boolean;
  canClose: boolean;
  openForm: { branch_id: string; cash_register_id: string; opening_amount: string };
  movementForm: { type: string; amount: string; notes: string };
  closingAmount: string;
  opening: boolean;
  moving: boolean;
  closing: boolean;
  onOpenForm: (value: { branch_id: string; cash_register_id: string; opening_amount: string }) => void;
  onMovementForm: (value: { type: string; amount: string; notes: string }) => void;
  onClosingAmount: (value: string) => void;
  onOpen: () => void;
  onMovement: () => void;
  onClose: () => void;
}) {
  const availableRegisters = openForm.branch_id
    ? registers.filter((register) => Number(register.branch_id) === Number(openForm.branch_id))
    : registers;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Banknote className="size-4" /> Mi turno
        </CardTitle>
        <CardDescription>El POS solo puede vender con un turno abierto en una caja fisica activa.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {loading ? (
          <LoadingLine label="Buscando tu turno abierto..." />
        ) : session ? (
          <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
            <div className="rounded border border-border bg-bg/40 p-3">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-xs uppercase text-text-muted">Caja abierta</p>
                  <p className="text-lg font-semibold">{session.cash_register?.name ?? 'Caja fisica'}</p>
                  <p className="text-sm text-text-muted">{session.branch?.name ?? 'Sucursal'} · abierta {formatDate(session.opened_at)}</p>
                </div>
                <Badge variant="success">Abierta</Badge>
              </div>
              <div className="mt-4 grid gap-2 sm:grid-cols-3">
                <Metric label="Fondo" value={money(session.opening_base_amount)} />
                <Metric label="Esperado" value={money(session.expected_base_amount)} />
                <Metric label="Estado" value="Turno activo" />
              </div>
            </div>
            <div className="space-y-3 rounded border border-border bg-bg/40 p-3">
              {canMove && (
                <div className="grid gap-2 sm:grid-cols-[130px_1fr_auto]">
                  <Select value={movementForm.type} onChange={(event) => onMovementForm({ ...movementForm, type: event.target.value })}>
                    <option value="inflow">Entrada</option>
                    <option value="outflow">Salida</option>
                    <option value="adjustment">Ajuste</option>
                  </Select>
                  <Input type="number" min="0" value={movementForm.amount} onChange={(event) => onMovementForm({ ...movementForm, amount: event.target.value })} placeholder="Monto USD" />
                  <Button disabled={moving} onClick={onMovement}>{moving && <Loader2 className="size-4 animate-spin" />} Registrar</Button>
                  <Input className="sm:col-span-3" value={movementForm.notes} onChange={(event) => onMovementForm({ ...movementForm, notes: event.target.value })} placeholder="Notas del movimiento" />
                </div>
              )}
              {canClose && (
                <div className="grid gap-2 sm:grid-cols-[1fr_auto]">
                  <Input type="number" min="0" value={closingAmount} onChange={(event) => onClosingAmount(event.target.value)} placeholder="Efectivo contado USD" />
                  <Button variant="danger" disabled={closing} onClick={onClose}>{closing && <Loader2 className="size-4 animate-spin" />} Cerrar turno</Button>
                </div>
              )}
              {!canMove && !canClose && (
                <p className="text-sm text-text-muted">No tienes permisos para movimientos o cierre de caja.</p>
              )}
            </div>
          </div>
        ) : (
          <div className="rounded border border-border bg-bg/40 p-3">
            {!canOpen ? (
              <p className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">No tienes permiso para abrir turno.</p>
            ) : (
              <div className="grid gap-2 lg:grid-cols-[1fr_1fr_160px_auto]">
                <Select value={openForm.branch_id} onChange={(event) => onOpenForm({ ...openForm, branch_id: event.target.value, cash_register_id: '' })}>
                  <option value="">Sucursal...</option>
                  {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} - {branch.name}</option>)}
                </Select>
                <Select value={openForm.cash_register_id} onChange={(event) => onOpenForm({ ...openForm, cash_register_id: event.target.value })}>
                  <option value="">Caja fisica...</option>
                  {availableRegisters.map((register) => <option key={register.id} value={register.id}>{register.code ?? register.id} - {register.name}</option>)}
                </Select>
                <Input type="number" min="0" value={openForm.opening_amount} onChange={(event) => onOpenForm({ ...openForm, opening_amount: event.target.value })} placeholder="Fondo USD" />
                <Button disabled={opening || !openForm.branch_id || !openForm.cash_register_id} onClick={onOpen}>
                  {opening && <Loader2 className="size-4 animate-spin" />} Abrir turno
                </Button>
              </div>
            )}
            {(branches.length === 0 || registers.length === 0) && (
              <p className="mt-3 text-sm text-warning">Configura al menos una sucursal y una caja fisica activa antes de abrir turno.</p>
            )}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function Metric({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <p className="text-xs uppercase text-text-muted">{label}</p>
      <p className="font-semibold">{value}</p>
    </div>
  );
}

function money(value: number | string | null | undefined): string {
  return `$${Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatDate(value?: string | null): string {
  if (!value) return '-';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '-' : date.toLocaleString('es-VE');
}

function LoadingLine({ label }: { label: string }) {
  return (
    <div className="flex items-center gap-2 rounded border border-border p-3 text-sm text-text-muted">
      <Loader2 className="size-4 animate-spin" /> {label}
    </div>
  );
}

function EmptySetup({ text }: { text: string }) {
  return (
    <div className="rounded border border-dashed border-border p-4 text-sm text-text-muted">
      {text}
    </div>
  );
}
