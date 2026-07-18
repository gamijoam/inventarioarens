import { createFileRoute, Link } from '@tanstack/react-router';
import { useState } from 'react';
import type React from 'react';
import { Check, KeyRound, ShieldCheck } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { useBootstrapStatus, useRunBootstrap } from '@/features/master/api';

export const Route = createFileRoute('/setup')({
  component: SetupPage,
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

function SetupPage() {
  const status = useBootstrapStatus();
  const bootstrap = useRunBootstrap();
  const [createTenant, setCreateTenant] = useState(true);
  const [name, setName] = useState('SaaS Master');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [token, setToken] = useState('');
  const [tenantName, setTenantName] = useState('Mi Empresa Inicial');
  const [tenantSlug, setTenantSlug] = useState('mi-empresa');
  const [plan, setPlan] = useState('standard');
  const [createdEmail, setCreatedEmail] = useState<string | null>(null);

  const blocked = status.data && !status.data.can_run;

  async function submit(event: React.FormEvent) {
    event.preventDefault();
    try {
      const result = await bootstrap.mutateAsync({
        name,
        email,
        password: password || undefined,
        bootstrap_token: token,
        tenant: createTenant
          ? {
              name: tenantName,
              slug: tenantSlug,
              plan,
            }
          : undefined,
      });
      setCreatedEmail(result.user.email);
      setToken('');
      toast.success('Instalacion inicial creada. Ya puedes entrar al portal master.');
      await status.refetch();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : 'No se pudo crear la instalacion inicial.');
    }
  }

  return (
    <main className="min-h-screen bg-bg px-6 py-10 text-text">
      <section className="mx-auto max-w-3xl">
        <div className="mb-8 flex items-center gap-3">
          <div className="bg-primary text-primary-foreground flex size-12 items-center justify-center rounded">
            <ShieldCheck className="size-6" />
          </div>
          <div>
            <h1 className="text-3xl font-bold">Instalacion inicial</h1>
            <p className="text-text-muted">Crea el primer administrador de plataforma cuando la base de datos esta vacia.</p>
          </div>
        </div>

        {status.isLoading ? (
          <div className="border-border rounded border bg-surface p-6">Verificando estado...</div>
        ) : blocked ? (
          <div className="border-border rounded border bg-surface p-6">
            <h2 className="text-xl font-semibold">La instalacion ya no esta vacia</h2>
            <p className="text-text-muted mt-2">
              Usuarios: {status.data?.user_count ?? 0} · Tenants: {status.data?.tenant_count ?? 0}. El bootstrap queda bloqueado por seguridad.
            </p>
            {!status.data?.enabled && (
              <p className="text-warning mt-3 text-sm">APP_BOOTSTRAP_TOKEN no esta configurado.</p>
            )}
            <Button asChild className="mt-5">
              <Link to="/master/login">Ir al login SaaS Master</Link>
            </Button>
          </div>
        ) : createdEmail ? (
          <div className="border-success/40 bg-success/10 rounded border p-6">
            <div className="flex items-center gap-2 font-semibold text-success">
              <Check className="size-5" /> Platform Admin creado
            </div>
            <p className="text-text-muted mt-2">Cuenta creada: {createdEmail}</p>
            <Button asChild className="mt-5">
              <Link to="/master/login">Entrar al portal master</Link>
            </Button>
          </div>
        ) : (
          <form onSubmit={submit} className="border-border rounded border bg-surface p-6 shadow-sm">
            <div className="grid gap-4 md:grid-cols-2">
              <Field label="Nombre">
                <Input value={name} onChange={(e) => setName(e.target.value)} required minLength={2} />
              </Field>
              <Field label="Email">
                <Input type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
              </Field>
              <Field label="Contrasena">
                <Input type="password" value={password} onChange={(e) => setPassword(e.target.value)} minLength={8} required />
              </Field>
              <Field label="Bootstrap token">
                <Input type="password" value={token} onChange={(e) => setToken(e.target.value)} required />
              </Field>
            </div>

            <label className="mt-6 flex items-center gap-2 text-sm">
              <input type="checkbox" checked={createTenant} onChange={(e) => setCreateTenant(e.target.checked)} />
              Crear organizacion y empresa inicial
            </label>

            {createTenant && (
              <div className="mt-4 grid gap-4 border-t border-border pt-4 md:grid-cols-3">
                <Field label="Empresa inicial">
                  <Input
                    value={tenantName}
                    onChange={(e) => {
                      setTenantName(e.target.value);
                      setTenantSlug(slugify(e.target.value));
                    }}
                    required
                  />
                </Field>
                <Field label="Slug">
                  <Input value={tenantSlug} onChange={(e) => setTenantSlug(slugify(e.target.value))} required />
                </Field>
                <Field label="Plan">
                  <Input value={plan} onChange={(e) => setPlan(e.target.value)} />
                </Field>
              </div>
            )}

            <Button className="mt-6 w-full" loading={bootstrap.isPending}>
              <KeyRound className="size-4" /> Crear instalacion
            </Button>
          </form>
        )}
      </section>
    </main>
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
