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
  useCashSessionsList,
  useCloseCashSession,
  useCreateCashRegister,
  useCreatePosBranch,
  useCurrentExchangeRatesForPos,
  useExchangeRateTypesForPos,
  useOpenCashSession,
} from './api';

type CloseForm = { sessionId: number | null; usd: string; ves: string; notes: string };

export function CashRegisterSetup() {
  const { data: branches = [], isLoading: loadingBranches } = useBranchesForPos();
  const { data: registers = [], isLoading: loadingRegisters } = useCashRegisters();
  const { data: mySessions = [], isLoading: loadingMySession } = useCashSessions();
  const { data: openSessions = [], isLoading: loadingOpenSessions } = useCashSessionsList({ status: 'open', perPage: 50 });
  const { data: closedSessions = [], isLoading: loadingClosedSessions } = useCashSessionsList({ status: 'closed', perPage: 25 });
  const { data: rates = [] } = useCurrentExchangeRatesForPos();
  const { data: rateTypes = [] } = useExchangeRateTypesForPos();
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
  const [openForm, setOpenForm] = useState({ branch_id: '', cash_register_id: '', opening_base_amount: '0', opening_local_amount: '0' });
  const [movementForm, setMovementForm] = useState({ type: 'outflow', amount: '', notes: '' });
  const [closeForm, setCloseForm] = useState<CloseForm>({ sessionId: null, usd: '', ves: '', notes: '' });

  const branchOptions = useMemo(() => branches.filter((branch) => (branch.status ?? 'active') === 'active'), [branches]);
  const registerOptions = useMemo(() => registers.filter((register) => (register.status ?? 'active') === 'active'), [registers]);
  const activeSession = mySessions.find((session) => session.status === 'open' && Boolean(session.cash_register_id)) ?? null;
  const activeRate = bestActiveRate(rates, rateTypes);
  const rateLabel = activeRate ? `${activeRate.code} @ ${formatLocalNumber(activeRate.rate)}` : null;

  function submitOpen(): void {
    if (!openForm.branch_id || !openForm.cash_register_id) {
      toast.error('Selecciona sucursal y caja fisica activa.');
      return;
    }
    if (Number(openForm.opening_local_amount || 0) > 0 && !activeRate) {
      toast.error('Configura una tasa activa USD/VES antes de abrir con fondo VES.');
      return;
    }

    openSession.mutate({
      branch_id: Number(openForm.branch_id),
      cash_register_id: Number(openForm.cash_register_id),
      opening_base_amount: Number(openForm.opening_base_amount || 0),
      opening_local_amount: Number(openForm.opening_local_amount || 0),
      exchange_rate_type_id: Number(openForm.opening_local_amount || 0) > 0 ? activeRate?.exchange_rate_type_id : null,
      notes: 'Apertura desde modulo Cajas',
    }, {
      onSuccess: () => setOpenForm({ branch_id: '', cash_register_id: '', opening_base_amount: '0', opening_local_amount: '0' }),
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  function submitMovement(): void {
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
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  function submitClose(session: CashRegisterSession): void {
    const usd = Number(closeForm.usd || 0);
    const ves = Number(closeForm.ves || 0);
    const diff = closeDifference(session, closeForm, activeRate?.rate ?? null);

    if (usd < 0 || ves < 0) {
      toast.error('El efectivo contado no puede ser negativo.');
      return;
    }
    if (ves > 0 && !activeRate) {
      toast.error('Configura una tasa activa USD/VES antes de cerrar con efectivo VES.');
      return;
    }
    if (hasDifference(diff.base, diff.local) && !closeForm.notes.trim()) {
      toast.error('Indica una nota para justificar la diferencia de caja.');
      return;
    }

    closeSession.mutate({
      sessionId: session.id,
      payload: {
        counted_base_amount: usd,
        counted_local_amount: ves,
        exchange_rate_type_id: ves > 0 ? activeRate?.exchange_rate_type_id : null,
        closing_notes: closeForm.notes.trim() || null,
      },
    }, {
      onSuccess: () => setCloseForm({ sessionId: null, usd: '', ves: '', notes: '' }),
      onError: (error) => toast.error(errorMessage(error)),
    });
  }

  return (
    <PageLayout
      title="Cajas"
      description="Opera turnos, arqueos y cajas fisicas con control por permisos."
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
          loading={loadingMySession}
          branches={branchOptions}
          registers={registerOptions}
          canOpen={canOpen}
          canMove={canMove}
          canClose={canClose}
          openForm={openForm}
          movementForm={movementForm}
          closeForm={closeForm}
          rate={activeRate?.rate ?? null}
          rateLabel={rateLabel}
          opening={openSession.isPending}
          moving={addMovement.isPending}
          closing={closeSession.isPending}
          onOpenForm={setOpenForm}
          onMovementForm={setMovementForm}
          onCloseForm={setCloseForm}
          onOpen={submitOpen}
          onMovement={submitMovement}
          onClose={submitClose}
        />

        <SessionsBoard
          title="Turnos abiertos"
          description="Cajas actualmente pendientes de cierre. Las acciones dependen de permisos y rol."
          sessions={openSessions}
          loading={loadingOpenSessions}
          canClose={canClose}
          closeForm={closeForm}
          rate={activeRate?.rate ?? null}
          closing={closeSession.isPending}
          onCloseForm={setCloseForm}
          onClose={submitClose}
        />

        <SessionsBoard
          title="Turnos cerrados"
          description="Historial reciente con declarado, esperado y diferencia final."
          sessions={closedSessions}
          loading={loadingClosedSessions}
          canClose={false}
          closeForm={closeForm}
          rate={activeRate?.rate ?? null}
          closing={false}
          onCloseForm={setCloseForm}
          onClose={submitClose}
        />

        <div className="grid gap-4 lg:grid-cols-[1fr_1fr]">
          <BranchesCard
            branches={branches}
            branchOptions={branchOptions}
            loading={loadingBranches}
            form={branchForm}
            creating={createBranch.isPending}
            onForm={setBranchForm}
            onCreate={() => {
              if (!branchForm.name.trim() || !branchForm.code.trim()) {
                toast.error('Indica nombre y codigo de la sucursal.');
                return;
              }
              createBranch.mutate({
                name: branchForm.name.trim(),
                code: branchForm.code.trim().toUpperCase(),
                status: 'active',
              }, { onSuccess: () => setBranchForm({ name: '', code: '' }) });
            }}
          />
          <RegistersCard
            registers={registers}
            branchOptions={branchOptions}
            loading={loadingRegisters}
            form={registerForm}
            creating={createRegister.isPending}
            onForm={setRegisterForm}
            onCreate={() => {
              if (!registerForm.name.trim() || !registerForm.code.trim() || !registerForm.branch_id) {
                toast.error('Indica nombre, codigo y sucursal de la caja.');
                return;
              }
              createRegister.mutate({
                name: registerForm.name.trim(),
                code: registerForm.code.trim().toUpperCase(),
                branch_id: Number(registerForm.branch_id),
                status: 'active',
              }, { onSuccess: () => setRegisterForm({ name: '', code: '', branch_id: '' }) });
            }}
          />
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
  closeForm,
  rate,
  rateLabel,
  opening,
  moving,
  closing,
  onOpenForm,
  onMovementForm,
  onCloseForm,
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
  openForm: { branch_id: string; cash_register_id: string; opening_base_amount: string; opening_local_amount: string };
  movementForm: { type: string; amount: string; notes: string };
  closeForm: CloseForm;
  rate: number | null;
  rateLabel: string | null;
  opening: boolean;
  moving: boolean;
  closing: boolean;
  onOpenForm: (value: { branch_id: string; cash_register_id: string; opening_base_amount: string; opening_local_amount: string }) => void;
  onMovementForm: (value: { type: string; amount: string; notes: string }) => void;
  onCloseForm: (value: CloseForm) => void;
  onOpen: () => void;
  onMovement: () => void;
  onClose: (session: CashRegisterSession) => void;
}) {
  const availableRegisters = openForm.branch_id
    ? registers.filter((register) => Number(register.branch_id) === Number(openForm.branch_id))
    : registers;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <Banknote className="size-4" /> Mi turno abierto
        </CardTitle>
        <CardDescription>El POS solo puede vender con un turno propio abierto en una caja fisica activa.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {loading ? (
          <LoadingLine label="Buscando tu turno abierto..." />
        ) : session ? (
          <div className="grid gap-4 xl:grid-cols-[1fr_420px]">
            <SessionSummary session={session} />
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
              {canClose ? (
                <ClosePanel session={session} form={closeForm} rate={rate} closing={closing} onForm={onCloseForm} onClose={onClose} />
              ) : (
                <p className="text-sm text-text-muted">No tienes permiso para cerrar este turno.</p>
              )}
            </div>
          </div>
        ) : (
          <div className="rounded border border-border bg-bg/40 p-3">
            {!canOpen ? (
              <p className="rounded border border-warning bg-warning/10 p-3 text-sm text-warning">No tienes permiso para abrir turno.</p>
            ) : (
              <>
                <div className="grid gap-2 lg:grid-cols-[1fr_1fr_140px_140px_auto]">
                  <Select value={openForm.branch_id} onChange={(event) => onOpenForm({ ...openForm, branch_id: event.target.value, cash_register_id: '' })}>
                    <option value="">Sucursal...</option>
                    {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} - {branch.name}</option>)}
                  </Select>
                  <Select value={openForm.cash_register_id} onChange={(event) => onOpenForm({ ...openForm, cash_register_id: event.target.value })}>
                    <option value="">Caja fisica...</option>
                    {availableRegisters.map((register) => <option key={register.id} value={register.id}>{register.code ?? register.id} - {register.name}</option>)}
                  </Select>
                  <Input type="number" min="0" value={openForm.opening_base_amount} onChange={(event) => onOpenForm({ ...openForm, opening_base_amount: event.target.value })} placeholder="Fondo USD" />
                  <Input type="number" min="0" value={openForm.opening_local_amount} onChange={(event) => onOpenForm({ ...openForm, opening_local_amount: event.target.value })} placeholder="Fondo VES" />
                  <Button disabled={opening || !openForm.branch_id || !openForm.cash_register_id} onClick={onOpen}>
                    {opening && <Loader2 className="size-4 animate-spin" />} Abrir turno
                  </Button>
                </div>
                <p className="mt-2 text-xs text-text-muted">
                  {rateLabel ? `Fondo VES se convierte con ${rateLabel}.` : 'Sin tasa activa USD/VES para convertir fondo VES.'}
                </p>
              </>
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

function SessionsBoard({
  title,
  description,
  sessions,
  loading,
  canClose,
  closeForm,
  rate,
  closing,
  onCloseForm,
  onClose,
}: {
  title: string;
  description: string;
  sessions: CashRegisterSession[];
  loading: boolean;
  canClose: boolean;
  closeForm: CloseForm;
  rate: number | null;
  closing: boolean;
  onCloseForm: (value: CloseForm) => void;
  onClose: (session: CashRegisterSession) => void;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="space-y-3">
        {loading && <LoadingLine label="Cargando turnos..." />}
        {!loading && sessions.length === 0 && <EmptySetup text="No hay turnos para mostrar." />}
        {sessions.map((session) => (
          <div key={session.id} className="rounded border border-border p-3">
            <div className="grid gap-3 lg:grid-cols-[1fr_auto]">
              <SessionSummary session={session} compact />
              <div className="flex flex-col items-start gap-2 lg:items-end">
                <Badge variant={session.status === 'open' ? 'success' : 'default'}>{session.status === 'open' ? 'Abierta' : 'Cerrada'}</Badge>
                {canClose && session.status === 'open' && (
                  <Button size="sm" variant="outline" onClick={() => onCloseForm({ sessionId: session.id, usd: String(Number(session.expected_base_amount ?? 0).toFixed(2)), ves: String(Number(session.expected_local_amount ?? 0).toFixed(2)), notes: '' })}>
                    Cerrar turno
                  </Button>
                )}
              </div>
            </div>
            {canClose && closeForm.sessionId === session.id && (
              <div className="mt-3 border-t border-border pt-3">
                <ClosePanel session={session} form={closeForm} rate={rate} closing={closing} onForm={onCloseForm} onClose={onClose} />
              </div>
            )}
          </div>
        ))}
      </CardContent>
    </Card>
  );
}

function SessionSummary({ session, compact = false }: { session: CashRegisterSession; compact?: boolean }) {
  return (
    <div className={compact ? '' : 'rounded border border-border bg-bg/40 p-3'}>
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <p className="text-xs uppercase text-text-muted">{session.status === 'open' ? 'Caja abierta' : 'Caja cerrada'}</p>
          <p className="text-lg font-semibold">{session.cash_register?.name ?? 'Caja fisica'}</p>
          <p className="text-sm text-text-muted">{session.branch?.name ?? 'Sucursal'} - Cajero: {session.cashier?.name ?? session.cashier_id ?? '-'}</p>
          <p className="text-xs text-text-muted">Abierta {formatDate(session.opened_at)}{session.closed_at ? ` - Cerrada ${formatDate(session.closed_at)}` : ''}</p>
        </div>
      </div>
      <div className="mt-4 grid gap-2 sm:grid-cols-4">
        <Metric label="Fondo USD" value={money(session.opening_base_amount)} />
        <Metric label="Fondo VES" value={localMoney(session.opening_local_amount)} />
        <Metric label="Esperado USD" value={money(session.expected_base_amount)} />
        <Metric label="Esperado VES" value={localMoney(session.expected_local_amount)} />
        {session.status === 'closed' && (
          <>
            <Metric label="Declarado USD" value={money(session.counted_base_amount)} />
            <Metric label="Declarado VES" value={localMoney(session.counted_local_amount)} />
            <Metric label="Diferencia USD" value={money(session.difference_base_amount)} />
            <Metric label="Diferencia VES" value={localMoney(session.difference_local_amount)} />
          </>
        )}
      </div>
      {session.movements && session.movements.length > 0 && (
        <div className="mt-4 divide-y divide-border rounded border border-border bg-surface">
          {session.movements.slice(0, 5).map((movement) => (
            <div key={movement.id} className="flex items-center justify-between gap-3 p-2 text-sm">
              <span>{movement.type} - {movement.notes ?? movement.method ?? 'movimiento'}</span>
              <span className="font-medium">{movement.currency === 'VES' ? localMoney(movement.amount) : money(movement.amount)}</span>
            </div>
          ))}
        </div>
      )}
      {session.closing_notes && <p className="mt-3 text-sm text-text-muted">Nota de cierre: {session.closing_notes}</p>}
    </div>
  );
}

function ClosePanel({ session, form, rate, closing, onForm, onClose }: {
  session: CashRegisterSession;
  form: CloseForm;
  rate: number | null;
  closing: boolean;
  onForm: (value: CloseForm) => void;
  onClose: (session: CashRegisterSession) => void;
}) {
  const activeForm = form.sessionId === session.id ? form : { sessionId: session.id, usd: '', ves: '', notes: '' };
  const diff = closeDifference(session, activeForm, rate);
  const needsNote = hasDifference(diff.base, diff.local);

  return (
    <div className="space-y-2 rounded border border-border bg-surface p-3">
      <p className="font-semibold">Arqueo de cierre</p>
      <div className="grid gap-2 sm:grid-cols-2">
        <Input type="number" min="0" value={activeForm.usd} onChange={(event) => onForm({ ...activeForm, usd: event.target.value })} placeholder="Efectivo contado USD" />
        <Input type="number" min="0" value={activeForm.ves} onChange={(event) => onForm({ ...activeForm, ves: event.target.value })} placeholder="Efectivo contado VES" />
      </div>
      <div className="grid gap-2 rounded border border-border/70 p-2 text-sm sm:grid-cols-2">
        <Metric label="Declarado USD equivalente" value={money(diff.declaredBase)} />
        <Metric label="Diferencia USD" value={money(diff.base)} />
        <Metric label="Declarado VES" value={localMoney(Number(activeForm.ves || 0))} />
        <Metric label="Diferencia VES" value={localMoney(diff.local)} />
      </div>
      <Input value={activeForm.notes} onChange={(event) => onForm({ ...activeForm, notes: event.target.value })} placeholder={needsNote ? 'Nota obligatoria por diferencia' : 'Notas de cierre'} />
      <Button variant="danger" disabled={closing || (needsNote && !activeForm.notes.trim())} onClick={() => onClose(session)}>
        {closing && <Loader2 className="size-4 animate-spin" />} Cerrar turno
      </Button>
    </div>
  );
}

function BranchesCard({ branches, loading, form, creating, onForm, onCreate }: {
  branches: Array<{ id: number; name: string; code: string; status?: string | null }>;
  branchOptions: Array<{ id: number; name: string; code: string }>;
  loading: boolean;
  form: { name: string; code: string };
  creating: boolean;
  onForm: (value: { name: string; code: string }) => void;
  onCreate: () => void;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2"><Building2 className="size-4" /> Sucursales</CardTitle>
        <CardDescription>Una caja fisica siempre pertenece a una sucursal.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <Can I={PERMISSIONS.BRANCHES_CREATE}>
          <div className="grid gap-2 rounded border border-border bg-bg/40 p-3 sm:grid-cols-[1fr_120px_auto]">
            <Input value={form.name} onChange={(event) => onForm({ ...form, name: event.target.value })} placeholder="Nombre de sucursal" />
            <Input value={form.code} onChange={(event) => onForm({ ...form, code: event.target.value })} placeholder="Codigo" />
            <Button disabled={creating} onClick={onCreate}>{creating ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />} Crear</Button>
          </div>
        </Can>
        {loading ? <LoadingLine label="Cargando sucursales..." /> : branches.length === 0 ? <EmptySetup text="No hay sucursales configuradas." /> : (
          <div className="divide-y divide-border rounded border border-border">
            {branches.map((branch) => (
              <div key={branch.id} className="flex items-center justify-between gap-3 p-3">
                <div>
                  <p className="font-medium">{branch.name}</p>
                  <p className="font-mono text-xs text-text-muted">{branch.code}</p>
                </div>
                <Badge variant={(branch.status ?? 'active') === 'active' ? 'success' : 'default'}>{branch.status ?? 'active'}</Badge>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function RegistersCard({ registers, branchOptions, loading, form, creating, onForm, onCreate }: {
  registers: Array<{ id: number; name: string; code?: string | null; status?: string | null; open_session?: unknown }>;
  branchOptions: Array<{ id: number; name: string; code: string }>;
  loading: boolean;
  form: { name: string; code: string; branch_id: string };
  creating: boolean;
  onForm: (value: { name: string; code: string; branch_id: string }) => void;
  onCreate: () => void;
}) {
  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2"><Store className="size-4" /> Cajas fisicas</CardTitle>
        <CardDescription>Estas son las cajas que el cajero puede abrir desde POS.</CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        <Can I={PERMISSIONS.CASH_REGISTER_CREATE}>
          <div className="grid gap-2 rounded border border-border bg-bg/40 p-3">
            <div className="grid gap-2 sm:grid-cols-[1fr_120px]">
              <Input value={form.name} onChange={(event) => onForm({ ...form, name: event.target.value })} placeholder="Nombre de caja" />
              <Input value={form.code} onChange={(event) => onForm({ ...form, code: event.target.value })} placeholder="Codigo" />
            </div>
            <Select value={form.branch_id} onChange={(event) => onForm({ ...form, branch_id: event.target.value })}>
              <option value="">Sucursal...</option>
              {branchOptions.map((branch) => <option key={branch.id} value={branch.id}>{branch.code} - {branch.name}</option>)}
            </Select>
            <Button disabled={creating || branchOptions.length === 0} onClick={onCreate}>{creating ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />} Crear caja</Button>
          </div>
        </Can>
        {loading ? <LoadingLine label="Cargando cajas..." /> : registers.length === 0 ? <EmptySetup text="No hay cajas fisicas configuradas." /> : (
          <div className="divide-y divide-border rounded border border-border">
            {registers.map((register) => (
              <div key={register.id} className="flex items-center justify-between gap-3 p-3">
                <div>
                  <p className="font-medium">{register.name}</p>
                  <p className="font-mono text-xs text-text-muted">{register.code ?? register.id}</p>
                </div>
                <div className="text-right">
                  <Badge variant={(register.status ?? 'active') === 'active' ? 'success' : 'default'}>{register.status ?? 'active'}</Badge>
                  {Boolean(register.open_session) && <p className="mt-1 text-xs text-success">Turno abierto</p>}
                </div>
              </div>
            ))}
          </div>
        )}
      </CardContent>
    </Card>
  );
}

function closeDifference(session: CashRegisterSession, form: CloseForm, rate: number | null) {
  const usd = Number(form.usd || 0);
  const ves = Number(form.ves || 0);
  const vesBase = rate && rate > 0 ? ves / rate : 0;
  const declaredBase = usd + vesBase;
  const base = declaredBase - Number(session.expected_base_amount ?? 0);
  const local = ves - Number(session.expected_local_amount ?? 0);

  return { declaredBase, base, local };
}

function hasDifference(base: number, local: number): boolean {
  return Math.abs(base) >= 0.01 || Math.abs(local) >= 0.01;
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

function localMoney(value: number | string | null | undefined): string {
  return `Bs ${Number(value ?? 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function formatLocalNumber(value: number): string {
  return Number(value || 0).toLocaleString('es-VE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function bestActiveRate(
  rates: Array<{ exchange_rate_type_id: number; exchange_rate_type_code?: string | null; rate: number; base_currency?: string; quote_currency?: string }>,
  rateTypes: Array<{ id: number; code?: string; is_default?: boolean; is_active?: boolean }>,
): { exchange_rate_type_id: number; code: string; rate: number } | null {
  const validRates = rates.filter((rate) => {
    const base = rate.base_currency ?? 'USD';
    const quote = rate.quote_currency ?? 'VES';
    return base === 'USD' && quote === 'VES' && Number(rate.rate) > 0;
  });
  const defaultType = rateTypes.find((rateType) => rateType.is_default && rateType.is_active !== false);
  const selected = validRates.find((rate) => defaultType && rate.exchange_rate_type_id === defaultType.id) ?? validRates[0];
  if (!selected) return null;
  const type = rateTypes.find((rateType) => rateType.id === selected.exchange_rate_type_id);

  return {
    exchange_rate_type_id: selected.exchange_rate_type_id,
    code: selected.exchange_rate_type_code ?? type?.code ?? 'Tasa',
    rate: Number(selected.rate),
  };
}

function errorMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  return 'No se pudo completar la accion.';
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
