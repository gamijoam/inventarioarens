import { createFileRoute, Outlet, redirect, useLocation, useNavigate } from '@tanstack/react-router';
import { useEffect, useMemo, useState } from 'react';
import type React from 'react';
import { Building2, LogOut, Plus, RefreshCcw, ShieldCheck, Users } from 'lucide-react';
import { toast } from 'sonner';

import { useAuth } from '@/auth/useAuth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { useSessionStore } from '@/stores/session';
import {
  type MasterAdmin,
  type MasterTenant,
  useCreateMasterAdmin,
  useCreateMasterGroup,
  useCreateMasterSpinoff,
  useDeactivateMasterGroup,
  useDeactivateMasterSpinoff,
  useMasterAdmins,
  useMasterGroups,
  useMasterSpinoffs,
  useMasterStats,
  useResetMasterAdminPassword,
  useRevokeMasterAdmin,
  useUpdateMasterAdmin,
  useUpdateMasterGroup,
  useUpdateMasterSpinoff,
} from '@/features/master/api';

export const Route = createFileRoute('/master')({
  beforeLoad: ({ location }) => {
    if (location.pathname === '/master/login') {
      return;
    }

    const { user, tenant } = useSessionStore.getState();
    if (!user) {
      throw redirect({ to: '/master/login' });
    }
    if (!user.is_platform_admin) {
      throw redirect({ to: tenant ? '/dashboard' : '/login' });
    }
  },
  component: MasterPortal,
});

function slugify(value: string): string {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 100);
}

function MasterPortal() {
  const location = useLocation();

  if (location.pathname === '/master/login') {
    return <Outlet />;
  }

  return <MasterDashboard />;
}

function MasterDashboard() {
  const user = useSessionStore((s) => s.user);
  const navigate = useNavigate();
  const { signOut } = useAuth();
  const stats = useMasterStats();
  const groups = useMasterGroups();
  const admins = useMasterAdmins();
  const [selectedGroupId, setSelectedGroupId] = useState<number | null>(null);
  const spinoffs = useMasterSpinoffs(selectedGroupId);

  useEffect(() => {
    if (selectedGroupId == null && groups.data && groups.data.length > 0) {
      setSelectedGroupId(groups.data[0]!.id);
    }
  }, [groups.data, selectedGroupId]);

  const selectedGroup = useMemo(
    () => groups.data?.find((group) => group.id === selectedGroupId) ?? null,
    [groups.data, selectedGroupId],
  );

  async function logout() {
    await signOut();
    await navigate({ to: '/master/login' });
  }

  async function refreshAll() {
    await Promise.all([stats.refetch(), groups.refetch(), admins.refetch(), spinoffs.refetch()]);
  }

  return (
    <main className="min-h-screen bg-bg text-text">
      <header className="border-border flex items-center justify-between border-b bg-surface px-6 py-4">
        <div className="flex items-center gap-3">
          <div className="bg-primary text-primary-foreground flex size-11 items-center justify-center rounded">
            <ShieldCheck className="size-5" />
          </div>
          <div>
            <h1 className="text-xl font-bold">SaaS Master</h1>
            <p className="text-text-muted text-sm">{user?.name} · Plataforma global</p>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={refreshAll}>
            <RefreshCcw className="size-4" /> Actualizar
          </Button>
          <Button variant="ghost" onClick={logout}>
            <LogOut className="size-4" /> Salir
          </Button>
        </div>
      </header>

      <div className="mx-auto max-w-7xl space-y-5 px-6 py-6">
        <StatsGrid stats={stats.data} loading={stats.isLoading} />

        <section className="grid gap-5 xl:grid-cols-[1.25fr_1fr]">
          <OrganizationsPanel
            groups={groups.data ?? []}
            selectedGroupId={selectedGroupId}
            loading={groups.isLoading}
            onSelect={setSelectedGroupId}
          />
          <SpinoffsPanel
            group={selectedGroup}
            groups={groups.data ?? []}
            spinoffs={spinoffs.data ?? []}
            loading={spinoffs.isLoading}
          />
        </section>

        <AdminsPanel admins={admins.data ?? []} loading={admins.isLoading} currentUserId={user?.id ?? null} />
      </div>
    </main>
  );
}

function StatsGrid({ stats, loading }: { stats?: { totals: Record<string, number>; groups_by_plan: Record<string, number> }; loading: boolean }) {
  const totals = stats?.totals;
  const cards = [
    ['Organizaciones', totals?.total_groups ?? 0],
    ['Empresas hijas', totals?.total_spinoffs ?? 0],
    ['Activas', totals?.active_tenants ?? 0],
    ['Inactivas', totals?.inactive_tenants ?? 0],
    ['Platform Admins', totals?.platform_admins ?? 0],
  ];

  return (
    <section className="grid gap-3 md:grid-cols-5">
      {cards.map(([label, value]) => (
        <div key={label} className="border-border rounded border bg-surface p-4">
          <div className="text-text-muted text-xs uppercase">{label}</div>
          <div className="mt-2 text-2xl font-bold">{loading ? '-' : value}</div>
        </div>
      ))}
    </section>
  );
}

function OrganizationsPanel({
  groups,
  selectedGroupId,
  loading,
  onSelect,
}: {
  groups: MasterTenant[];
  selectedGroupId: number | null;
  loading: boolean;
  onSelect: (id: number) => void;
}) {
  const createGroup = useCreateMasterGroup();
  const updateGroup = useUpdateMasterGroup();
  const deactivateGroup = useDeactivateMasterGroup();
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<MasterTenant | null>(null);

  async function deactivate(group: MasterTenant) {
    if (!window.confirm(`Desactivar organizacion "${group.name}"?`)) return;
    await deactivateGroup.mutateAsync(group.id);
    toast.success('Organizacion desactivada.');
  }

  return (
    <section className="border-border rounded border bg-surface">
      <div className="border-border flex items-center justify-between border-b p-4">
        <div>
          <h2 className="flex items-center gap-2 font-semibold">
            <Building2 className="size-4" /> Organizaciones
          </h2>
          <p className="text-text-muted text-sm">Grupos/holdings administrados por plataforma.</p>
        </div>
        <Button size="sm" onClick={() => setShowCreate((v) => !v)}>
          <Plus className="size-4" /> Crear
        </Button>
      </div>

      {showCreate && (
        <GroupForm
          title="Crear organizacion"
          onCancel={() => setShowCreate(false)}
          onSubmit={async (values) => {
            await createGroup.mutateAsync({
              name: values.name,
              slug: values.slug,
              plan: values.plan,
              domain: values.domain,
              group_owner: {
                name: values.ownerName || values.name,
                email: values.ownerEmail,
                password: values.ownerPassword || undefined,
              },
            });
            setShowCreate(false);
            toast.success('Organizacion creada.');
          }}
        />
      )}

      {editing && (
        <GroupForm
          title="Editar organizacion"
          initial={editing}
          onCancel={() => setEditing(null)}
          onSubmit={async (values) => {
            await updateGroup.mutateAsync({
              id: editing.id,
              payload: {
                name: values.name,
                slug: values.slug,
                plan: values.plan,
                domain: values.domain,
                status: values.status,
              },
            });
            setEditing(null);
            toast.success('Organizacion actualizada.');
          }}
        />
      )}

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-bg text-left text-xs uppercase text-text-muted">
            <tr>
              <th className="px-4 py-3">Nombre</th>
              <th className="px-4 py-3">Plan</th>
              <th className="px-4 py-3">Estado</th>
              <th className="px-4 py-3">Empresas</th>
              <th className="px-4 py-3 text-right">Accion</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td className="px-4 py-5 text-text-muted" colSpan={5}>Cargando...</td></tr>
            ) : groups.length === 0 ? (
              <tr><td className="px-4 py-5 text-text-muted" colSpan={5}>No hay organizaciones.</td></tr>
            ) : (
              groups.map((group) => (
                <tr key={group.id} className={selectedGroupId === group.id ? 'bg-primary/5' : ''}>
                  <td className="px-4 py-3">
                    <button className="text-left font-medium" onClick={() => onSelect(group.id)}>
                      {group.name}
                      <span className="block text-xs font-normal text-text-muted">{group.slug}</span>
                    </button>
                  </td>
                  <td className="px-4 py-3">{group.plan ?? '-'}</td>
                  <td className="px-4 py-3"><StatusBadge status={group.status} /></td>
                  <td className="px-4 py-3">{group.spinoffs_count ?? 0}</td>
                  <td className="px-4 py-3 text-right">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(group)}>Editar</Button>
                    <Button size="sm" variant="ghost" onClick={() => deactivate(group)} disabled={group.status === 'inactive'}>Desactivar</Button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function SpinoffsPanel({
  group,
  groups,
  spinoffs,
  loading,
}: {
  group: MasterTenant | null;
  groups: MasterTenant[];
  spinoffs: MasterTenant[];
  loading: boolean;
}) {
  const createSpinoff = useCreateMasterSpinoff(group?.id ?? null);
  const updateSpinoff = useUpdateMasterSpinoff();
  const deactivateSpinoff = useDeactivateMasterSpinoff();
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<MasterTenant | null>(null);

  async function deactivate(tenant: MasterTenant) {
    if (!window.confirm(`Desactivar empresa "${tenant.name}"?`)) return;
    await deactivateSpinoff.mutateAsync(tenant.id);
    toast.success('Empresa desactivada.');
  }

  return (
    <section className="border-border rounded border bg-surface">
      <div className="border-border flex items-center justify-between border-b p-4">
        <div>
          <h2 className="font-semibold">Empresas hijas</h2>
          <p className="text-text-muted text-sm">{group ? group.name : 'Selecciona una organizacion.'}</p>
        </div>
        <Button size="sm" disabled={!group} onClick={() => setShowCreate((v) => !v)}>
          <Plus className="size-4" /> Crear
        </Button>
      </div>

      {showCreate && group && (
        <SpinoffForm
          title="Crear empresa hija"
          groups={[]}
          onCancel={() => setShowCreate(false)}
          onSubmit={async (values) => {
            await createSpinoff.mutateAsync({
              name: values.name,
              slug: values.slug,
              plan: values.plan,
              domain: values.domain,
              admin: {
                name: values.ownerName || values.name,
                email: values.ownerEmail,
                password: values.ownerPassword || undefined,
              },
            });
            setShowCreate(false);
            toast.success('Empresa hija creada.');
          }}
        />
      )}

      {editing && (
        <SpinoffForm
          title="Editar empresa"
          initial={editing}
          groups={groups}
          onCancel={() => setEditing(null)}
          onSubmit={async (values) => {
            await updateSpinoff.mutateAsync({
              id: editing.id,
              payload: {
                name: values.name,
                slug: values.slug,
                plan: values.plan,
                domain: values.domain,
                status: values.status,
                parent_id: values.parentId,
              },
            });
            setEditing(null);
            toast.success('Empresa actualizada.');
          }}
        />
      )}

      <div className="divide-y divide-border">
        {loading ? (
          <div className="p-4 text-sm text-text-muted">Cargando empresas...</div>
        ) : spinoffs.length === 0 ? (
          <div className="p-4 text-sm text-text-muted">No hay empresas hijas para esta organizacion.</div>
        ) : (
          spinoffs.map((tenant) => (
            <div key={tenant.id} className="flex items-center justify-between gap-3 p-4">
              <div>
                <div className="font-medium">{tenant.name}</div>
                <div className="text-text-muted text-xs">{tenant.slug} · {tenant.plan ?? 'sin plan'} · {tenant.users_count ?? 0} usuarios</div>
              </div>
              <div className="flex items-center gap-2">
                <StatusBadge status={tenant.status} />
                <Button size="sm" variant="ghost" onClick={() => setEditing(tenant)}>Editar</Button>
                <Button size="sm" variant="ghost" onClick={() => deactivate(tenant)} disabled={tenant.status === 'inactive'}>Desactivar</Button>
              </div>
            </div>
          ))
        )}
      </div>
    </section>
  );
}

function AdminsPanel({ admins, loading, currentUserId }: { admins: MasterAdmin[]; loading: boolean; currentUserId: number | null }) {
  const createAdmin = useCreateMasterAdmin();
  const updateAdmin = useUpdateMasterAdmin();
  const resetPassword = useResetMasterAdminPassword();
  const revokeAdmin = useRevokeMasterAdmin();
  const [showCreate, setShowCreate] = useState(false);
  const [editing, setEditing] = useState<MasterAdmin | null>(null);

  async function revoke(admin: MasterAdmin) {
    if (!window.confirm(`Revocar acceso master de "${admin.email}"?`)) return;
    await revokeAdmin.mutateAsync(admin.id);
    toast.success('Acceso master revocado.');
  }

  async function reset(admin: MasterAdmin) {
    const result = await resetPassword.mutateAsync({ id: admin.id });
    toast.success(result.initial_password ? `Clave temporal: ${result.initial_password}` : 'Contrasena actualizada.');
  }

  return (
    <section className="border-border rounded border bg-surface">
      <div className="border-border flex items-center justify-between border-b p-4">
        <div>
          <h2 className="flex items-center gap-2 font-semibold">
            <Users className="size-4" /> Platform Admins
          </h2>
          <p className="text-text-muted text-sm">Usuarios con acceso global al portal SaaS Master.</p>
        </div>
        <Button size="sm" onClick={() => setShowCreate((v) => !v)}>
          <Plus className="size-4" /> Crear admin
        </Button>
      </div>

      {showCreate && (
        <AdminForm
          title="Crear Platform Admin"
          onCancel={() => setShowCreate(false)}
          onSubmit={async (values) => {
            await createAdmin.mutateAsync(values);
            setShowCreate(false);
            toast.success('Platform Admin creado.');
          }}
        />
      )}

      {editing && (
        <AdminForm
          title="Editar Platform Admin"
          initial={editing}
          onCancel={() => setEditing(null)}
          onSubmit={async (values) => {
            await updateAdmin.mutateAsync({ id: editing.id, payload: { name: values.name, email: values.email } });
            setEditing(null);
            toast.success('Platform Admin actualizado.');
          }}
        />
      )}

      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="bg-bg text-left text-xs uppercase text-text-muted">
            <tr>
              <th className="px-4 py-3">Nombre</th>
              <th className="px-4 py-3">Email</th>
              <th className="px-4 py-3 text-right">Accion</th>
            </tr>
          </thead>
          <tbody>
            {loading ? (
              <tr><td className="px-4 py-5 text-text-muted" colSpan={3}>Cargando admins...</td></tr>
            ) : admins.length === 0 ? (
              <tr><td className="px-4 py-5 text-text-muted" colSpan={3}>No hay Platform Admins.</td></tr>
            ) : (
              admins.map((admin) => (
                <tr key={admin.id}>
                  <td className="px-4 py-3 font-medium">{admin.name}</td>
                  <td className="px-4 py-3">{admin.email}</td>
                  <td className="px-4 py-3 text-right">
                    <Button size="sm" variant="ghost" onClick={() => setEditing(admin)}>Editar</Button>
                    <Button size="sm" variant="ghost" onClick={() => reset(admin)}>Reset clave</Button>
                    <Button size="sm" variant="ghost" disabled={admin.id === currentUserId} onClick={() => revoke(admin)}>Revocar</Button>
                  </td>
                </tr>
              ))
            )}
          </tbody>
        </table>
      </div>
    </section>
  );
}

interface TenantFormValues {
  name: string;
  slug: string;
  domain: string;
  plan: string;
  status: string;
  parentId: number | null;
  ownerName: string;
  ownerEmail: string;
  ownerPassword: string;
}

function GroupForm(props: {
  title: string;
  initial?: MasterTenant;
  onSubmit: (values: TenantFormValues) => Promise<void>;
  onCancel: () => void;
}) {
  return <TenantForm {...props} ownerLabel="Owner" />;
}

function SpinoffForm(props: {
  title: string;
  initial?: MasterTenant;
  groups: MasterTenant[];
  onSubmit: (values: TenantFormValues) => Promise<void>;
  onCancel: () => void;
}) {
  return <TenantForm {...props} ownerLabel="Administrador" />;
}

function TenantForm({
  title,
  initial,
  ownerLabel,
  groups = [],
  onSubmit,
  onCancel,
}: {
  title: string;
  initial?: MasterTenant;
  ownerLabel: string;
  groups?: MasterTenant[];
  onSubmit: (values: TenantFormValues) => Promise<void>;
  onCancel: () => void;
}) {
  const [values, setValues] = useState<TenantFormValues>({
    name: initial?.name ?? '',
    slug: initial?.slug ?? '',
    domain: initial?.domain ?? '',
    plan: initial?.plan ?? 'standard',
    status: initial?.status ?? 'active',
    parentId: initial?.parent_id ?? null,
    ownerName: '',
    ownerEmail: '',
    ownerPassword: '',
  });
  const [loading, setLoading] = useState(false);
  const editing = Boolean(initial);

  function set<K extends keyof TenantFormValues>(key: K, value: TenantFormValues[K]) {
    setValues((current) => ({ ...current, [key]: value }));
  }

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    try {
      await onSubmit(values);
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={submit} className="border-border grid gap-3 border-b bg-bg/60 p-4 md:grid-cols-3">
      <div className="md:col-span-3 font-semibold">{title}</div>
      <Field label="Nombre">
        <Input
          value={values.name}
          onChange={(e) => {
            set('name', e.target.value);
            if (!editing) set('slug', slugify(e.target.value));
          }}
          required
        />
      </Field>
      <Field label="Slug">
        <Input value={values.slug} onChange={(e) => set('slug', slugify(e.target.value))} required />
      </Field>
      <Field label="Plan">
        <Input value={values.plan} onChange={(e) => set('plan', e.target.value)} />
      </Field>
      <Field label="Dominio">
        <Input value={values.domain} onChange={(e) => set('domain', e.target.value)} />
      </Field>
      {editing && (
        <Field label="Estado">
          <select className="border-border h-9 w-full rounded border bg-surface px-3 text-sm" value={values.status} onChange={(e) => set('status', e.target.value)}>
            <option value="active">Activa</option>
            <option value="inactive">Inactiva</option>
          </select>
        </Field>
      )}
      {editing && groups.length > 0 && (
        <Field label="Organizacion">
          <select
            className="border-border h-9 w-full rounded border bg-surface px-3 text-sm"
            value={values.parentId ?? ''}
            onChange={(e) => set('parentId', Number(e.target.value))}
            required
          >
            {groups.map((group) => (
              <option key={group.id} value={group.id}>
                {group.name}
              </option>
            ))}
          </select>
        </Field>
      )}
      {!editing && (
        <>
          <Field label={`Nombre ${ownerLabel}`}>
            <Input value={values.ownerName} onChange={(e) => set('ownerName', e.target.value)} />
          </Field>
          <Field label={`Email ${ownerLabel}`}>
            <Input type="email" value={values.ownerEmail} onChange={(e) => set('ownerEmail', e.target.value)} required />
          </Field>
          <Field label="Clave inicial">
            <Input type="password" value={values.ownerPassword} onChange={(e) => set('ownerPassword', e.target.value)} minLength={values.ownerPassword ? 8 : undefined} />
          </Field>
        </>
      )}
      <div className="flex gap-2 md:col-span-3">
        <Button loading={loading}>{editing ? 'Guardar' : 'Crear'}</Button>
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
      </div>
    </form>
  );
}

function AdminForm({
  title,
  initial,
  onSubmit,
  onCancel,
}: {
  title: string;
  initial?: MasterAdmin;
  onSubmit: (values: { name: string; email: string; password?: string }) => Promise<void>;
  onCancel: () => void;
}) {
  const [name, setName] = useState(initial?.name ?? '');
  const [email, setEmail] = useState(initial?.email ?? '');
  const [password, setPassword] = useState('');
  const [loading, setLoading] = useState(false);

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    setLoading(true);
    try {
      await onSubmit({ name, email, password: password || undefined });
    } finally {
      setLoading(false);
    }
  }

  return (
    <form onSubmit={submit} className="border-border grid gap-3 border-b bg-bg/60 p-4 md:grid-cols-3">
      <div className="md:col-span-3 font-semibold">{title}</div>
      <Field label="Nombre">
        <Input value={name} onChange={(e) => setName(e.target.value)} required />
      </Field>
      <Field label="Email">
        <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
      </Field>
      {!initial && (
        <Field label="Clave inicial">
          <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} minLength={password ? 8 : undefined} />
        </Field>
      )}
      <div className="flex gap-2 md:col-span-3">
        <Button loading={loading}>{initial ? 'Guardar' : 'Crear'}</Button>
        <Button type="button" variant="outline" onClick={onCancel}>Cancelar</Button>
      </div>
    </form>
  );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <div className="space-y-1">
      <Label>{label}</Label>
      {children}
    </div>
  );
}

function StatusBadge({ status }: { status?: string }) {
  const active = status === 'active';
  return (
    <span className={active ? 'rounded bg-success/10 px-2 py-1 text-xs font-medium text-success' : 'rounded bg-warning/10 px-2 py-1 text-xs font-medium text-warning'}>
      {active ? 'Activa' : 'Inactiva'}
    </span>
  );
}
