import { Copy, Laptop, ShieldCheck } from 'lucide-react';
import type { FormEvent, ReactNode } from 'react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Select } from '@/components/ui/Select';
import { formatDateTime } from '@/lib/format';

import { type GroupUser, type TenantGroup, type TenantSpinoff, useCreateSyncPairingCode } from './tenantGroupsApi';

interface PairDeviceDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  group: TenantGroup;
  spinoffs: TenantSpinoff[];
  users: GroupUser[];
}

export function PairDeviceDialog({ open, onOpenChange, group, spinoffs, users }: PairDeviceDialogProps) {
  const createCode = useCreateSyncPairingCode();
  const companies = useMemo(() => [
    { id: group.id, name: group.name, slug: group.slug },
    ...spinoffs.map((company) => ({ id: company.id, name: company.name, slug: company.slug })),
  ], [group, spinoffs]);
  const [tenantId, setTenantId] = useState(String(group.id));
  const [email, setEmail] = useState('');
  const [nodeName, setNodeName] = useState('Equipo local');
  const [minutes, setMinutes] = useState('15');
  const [created, setCreated] = useState<{ code: string; expiresAt: string; tenantName: string } | null>(null);

  const eligibleUsers = users.filter((user) => user.status === 'active' && user.tenants?.some((tenant) => tenant.id === Number(tenantId)));

  useEffect(() => {
    if (!open) return;
    setCreated(null);
    setTenantId(String(group.id));
    setEmail('');
  }, [group.id, open]);

  useEffect(() => {
    if (!eligibleUsers.some((user) => user.email === email)) {
      setEmail(eligibleUsers[0]?.email ?? '');
    }
  }, [email, eligibleUsers]);

  async function submit(event: FormEvent) {
    event.preventDefault();
    const target = companies.find((company) => company.id === Number(tenantId));
    if (!target) return;

    try {
      const result = await createCode.mutateAsync({
        target_tenant_id: target.id,
        user_email: email,
        node_name: nodeName,
        expires_in_minutes: Number(minutes),
      });
      setCreated({ code: result.code, expiresAt: result.expires_at, tenantName: result.tenant.name });
      toast.success('Codigo temporal creado. Copialo en la computadora que vas a vincular.');
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo crear el codigo.');
    }
  }

  async function copyCode() {
    if (!created) return;
    await navigator.clipboard.writeText(created.code);
    toast.success('Codigo copiado.');
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2"><Laptop className="size-5 text-primary" /> Vincular una computadora</DialogTitle>
          <DialogDescription>Genera un codigo de un solo uso para que un tecnico descargue una empresa en una computadora local.</DialogDescription>
        </DialogHeader>

        {created ? (
          <div className="space-y-4">
            <div className="rounded border border-success/40 bg-success/10 p-4">
              <div className="flex items-center gap-2 font-medium text-success"><ShieldCheck className="size-4" /> Codigo listo para {created.tenantName}</div>
              <p className="mt-2 break-all rounded bg-surface px-3 py-2 font-mono text-sm text-text-primary">{created.code}</p>
              <p className="mt-2 text-xs text-text-muted">Expira: {formatDateTime(created.expiresAt)}. Se invalida al usarlo una vez.</p>
            </div>
            <p className="text-sm text-text-muted">En la computadora local abre <strong>Soporte tecnico</strong>, pega este codigo y crea la clave local del usuario seleccionado.</p>
            <DialogFooter><Button variant="outline" onClick={() => setCreated(null)}>Crear otro</Button><Button onClick={() => void copyCode()}><Copy className="size-4" /> Copiar codigo</Button></DialogFooter>
          </div>
        ) : (
          <form className="space-y-4" onSubmit={submit}>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label="Empresa">
                <Select value={tenantId} onChange={(event) => setTenantId(event.target.value)}>
                  {companies.map((company) => <option key={company.id} value={company.id}>{company.name} ({company.slug})</option>)}
                </Select>
              </Field>
              <Field label="Usuario autorizado">
                <Select value={email} onChange={(event) => setEmail(event.target.value)} disabled={eligibleUsers.length === 0}>
                  {eligibleUsers.length === 0 ? <option value="">No hay usuario activo para esta empresa</option> : eligibleUsers.map((user) => <option key={user.id} value={user.email}>{user.name} ({user.email})</option>)}
                </Select>
              </Field>
              <Field label="Nombre del equipo"><Input value={nodeName} onChange={(event) => setNodeName(event.target.value)} required /></Field>
              <Field label="Vigencia"><Select value={minutes} onChange={(event) => setMinutes(event.target.value)}><option value="15">15 minutos</option><option value="30">30 minutos</option><option value="60">60 minutos</option></Select></Field>
            </div>
            <DialogFooter><Button type="submit" loading={createCode.isPending} disabled={!email}><Laptop className="size-4" /> Generar codigo temporal</Button></DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return <div className="space-y-1"><Label>{label}</Label>{children}</div>;
}
