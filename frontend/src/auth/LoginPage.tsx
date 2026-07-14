import { useEffect, useState, type FormEvent } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Building2, Lock, Mail, ShieldCheck } from 'lucide-react';

import { useSessionStore } from '@/stores/session';
import {
  APP_NAME,
  APP_TAGLINE,
  APP_DESCRIPTION,
  APP_FEATURES,
} from '@/config/branding';

import { lookupTenants } from '@/api/endpoints/auth';
import { useAuth } from '@/auth/useAuth';
import { Button } from '@/components/ui/Button';
import { Input } from '@/components/ui/Input';
import { Label } from '@/components/ui/Label';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Spinner';
import type { TenantOption } from '@/types/user';
import { cn } from '@/lib/cn';

const DEBOUNCE_MS = 500;

export function LoginPage() {
  const { signIn, isAuthenticated } = useAuth();
  const session = useSessionStore();
  const navigate = useNavigate();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenants, setTenants] = useState<TenantOption[]>([]);
  const [selectedTenant, setSelectedTenant] = useState<TenantOption | null>(null);
  const [lookupLoading, setLookupLoading] = useState(false);
  const [loginLoading, setLoginLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Lookup automatico de tenants con debounce.
  useEffect(() => {
    if (!isValidEmail(email)) {
      setTenants([]);
      setSelectedTenant(null);
      return;
    }
    const handle = window.setTimeout(async () => {
      setLookupLoading(true);
      setError(null);
      try {
        const data = await lookupTenants({ email });
        setTenants(data);
        if (data.length === 1) setSelectedTenant(data[0]!);
        else setSelectedTenant(null);
      } catch {
        setTenants([]);
        setSelectedTenant(null);
      } finally {
        setLookupLoading(false);
      }
    }, DEBOUNCE_MS);
    return () => window.clearTimeout(handle);
  }, [email]);

  // Si ya hay sesion activa, redirigir al dashboard.
  useEffect(() => {
    if (isAuthenticated) {
      void navigate({ to: '/dashboard' });
    }
  }, [isAuthenticated, navigate]);

  const handleSubmit = async (e: FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!selectedTenant) {
      setError('Selecciona una empresa para continuar.');
      return;
    }
    if (!email || !password) {
      setError('Email y contraseña son obligatorios.');
      return;
    }

    setLoginLoading(true);
    try {
      await signIn(selectedTenant.slug, {
        email,
        password,
        device_name: window.navigator.userAgent.slice(0, 100),
      });
      await navigate({ to: '/dashboard' });
    } catch (err) {
      // Si el login falla por tenant, limpiamos el temporal.
      session.clearSession();
      const message = err instanceof Error ? err.message : 'Error al iniciar sesión.';
      setError(message);
    } finally {
      setLoginLoading(false);
    }
  };

  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      {/* Panel izquierdo — branding */}
      <aside className="hidden flex-col justify-between bg-primary p-10 text-primary-foreground lg:flex">
        <div className="flex items-center gap-3 text-lg font-semibold">
          <div className="flex size-10 items-center justify-center rounded-md bg-white/15">
            <ShieldCheck className="size-5" aria-hidden="true" />
          </div>
          {APP_NAME}
        </div>

        <div className="space-y-4">
          <h2 className="text-3xl font-bold leading-tight">{APP_TAGLINE}</h2>
          <p className="text-base text-primary-foreground/80">{APP_DESCRIPTION}</p>
          <ul className="space-y-2 text-sm text-primary-foreground/80">
            {APP_FEATURES.map((feature) => (
              <li key={feature}>• {feature}</li>
            ))}
          </ul>
        </div>

        <p className="text-xs text-primary-foreground/60">
          v0.1 · Venezuela · USD base, VES operativo
        </p>
      </aside>

      {/* Panel derecho — formulario */}
      <main className="flex items-center justify-center bg-bg p-6 sm:p-10">
        <form
          onSubmit={handleSubmit}
          className="w-full max-w-sm space-y-5"
          aria-label="Formulario de inicio de sesión"
        >
          <header className="space-y-1 text-center">
            <h1 className="text-2xl font-semibold tracking-tight">Iniciar sesión</h1>
            <p className="text-sm text-text-muted">
              Ingresa tu email para ver las empresas donde estás activo.
            </p>
          </header>

          {error && (
            <Alert variant="danger">
              <AlertTitle>No pudimos iniciar sesión</AlertTitle>
              <AlertDescription>{error}</AlertDescription>
            </Alert>
          )}

          <div className="space-y-2">
            <Label htmlFor="email">Email</Label>
            <div className="relative">
              <Mail
                className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
                aria-hidden="true"
              />
              <Input
                id="email"
                type="email"
                autoComplete="email"
                required
                placeholder="usuario@empresa.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={loginLoading}
                className="pl-8"
                data-testid="login-email"
              />
              {lookupLoading && (
                <Spinner
                  size="sm"
                  className="absolute right-2 top-1/2 -translate-y-1/2"
                />
              )}
            </div>
          </div>

          <div className="space-y-2">
            <Label htmlFor="tenant">Empresa</Label>
            <TenantPicker
              tenants={tenants}
              selected={selectedTenant}
              onChange={setSelectedTenant}
              email={email}
              disabled={loginLoading}
              lookupLoading={lookupLoading}
            />
          </div>

          <div className="space-y-2">
            <Label htmlFor="password">Contraseña</Label>
            <div className="relative">
              <Lock
                className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
                aria-hidden="true"
              />
              <Input
                id="password"
                type="password"
                autoComplete="current-password"
                required
                placeholder="••••••••"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={loginLoading}
                className="pl-8"
                data-testid="login-password"
              />
            </div>
          </div>

          <Button
            type="submit"
            fullWidth
            loading={loginLoading}
            disabled={!selectedTenant || !email || !password}
            data-testid="login-submit"
          >
            Ingresar
          </Button>

          <p className="text-center text-xs text-text-muted">
            ¿Problemas para entrar? Contacta al administrador de tu empresa.
          </p>
        </form>
      </main>
    </div>
  );
}

function isValidEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

interface TenantPickerProps {
  tenants: TenantOption[];
  selected: TenantOption | null;
  onChange: (tenant: TenantOption | null) => void;
  email: string;
  disabled: boolean;
  lookupLoading: boolean;
}

function TenantPicker({ tenants, selected, onChange, email, disabled, lookupLoading }: TenantPickerProps) {
  if (!isValidEmail(email)) {
    return (
      <div className="rounded border border-dashed border-border bg-surface px-3 py-2 text-sm text-text-muted">
        Ingresa un email válido para buscar empresas.
      </div>
    );
  }
  if (lookupLoading) {
    return (
      <div className="rounded border border-dashed border-border bg-surface px-3 py-2 text-sm text-text-muted">
        Buscando empresas...
      </div>
    );
  }
  if (tenants.length === 0) {
    return (
      <div className="rounded border border-dashed border-warning bg-warning/5 px-3 py-2 text-sm text-warning">
        No hay empresas activas para este email.
      </div>
    );
  }
  if (tenants.length === 1) {
    return (
      <div className="flex items-center gap-2 rounded border border-border bg-surface px-3 py-2 text-sm">
        <Building2 className="size-4 text-text-muted" aria-hidden="true" />
        <span className="font-medium">{tenants[0]!.name}</span>
        <span className="text-xs text-text-muted">({tenants[0]!.slug})</span>
      </div>
    );
  }
  return (
    <select
      className={cn(
        'flex h-9 w-full rounded border border-border-strong bg-surface px-3 text-sm shadow-sm',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1',
        'disabled:cursor-not-allowed disabled:opacity-50',
      )}
      value={selected?.slug ?? ''}
      onChange={(e) => {
        const t = tenants.find((x) => x.slug === e.target.value) ?? null;
        onChange(t);
      }}
      disabled={disabled}
      data-testid="login-tenant"
    >
      <option value="">— Selecciona una empresa —</option>
      {tenants.map((t) => (
        <option key={t.id} value={t.slug}>
          {t.name} ({t.slug})
        </option>
      ))}
    </select>
  );
}