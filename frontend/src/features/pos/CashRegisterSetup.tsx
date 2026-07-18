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
import {
  useBranchesForPos,
  useCashRegisters,
  useCreateCashRegister,
  useCreatePosBranch,
} from './api';

export function CashRegisterSetup() {
  const { data: branches = [], isLoading: loadingBranches } = useBranchesForPos();
  const { data: registers = [], isLoading: loadingRegisters } = useCashRegisters();
  const createBranch = useCreatePosBranch();
  const createRegister = useCreateCashRegister();
  const [branchForm, setBranchForm] = useState({ name: '', code: '' });
  const [registerForm, setRegisterForm] = useState({ name: '', code: '', branch_id: '' });

  const branchOptions = useMemo(
    () => branches.filter((branch) => (branch.status ?? 'active') === 'active'),
    [branches],
  );

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
            <Can I={PERMISSIONS.CASH_REGISTER_OPEN}>
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
    </PageLayout>
  );
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
