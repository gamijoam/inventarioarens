import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import { Building2, Eye, EyeOff, Lock, Mail, ShieldCheck } from 'lucide-react';

import { APP_NAME } from '@/config/branding';
import { lookupTenants } from '@/api/endpoints/auth';
import { useAuth } from '@/auth/useAuth';
import { useSessionStore } from '@/stores/session';
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
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenants, setTenants] = useState<TenantOption[]>([]);
  const [selectedTenant, setSelectedTenant] = useState<TenantOption | null>(null);
  const [lookupLoading, setLookupLoading] = useState(false);
  const [loginLoading, setLoginLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState<string | null>(null);

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
        setSelectedTenant(data.length === 1 ? data[0]! : null);
      } catch {
        setTenants([]);
        setSelectedTenant(null);
      } finally {
        setLookupLoading(false);
      }
    }, DEBOUNCE_MS);

    return () => window.clearTimeout(handle);
  }, [email]);

  useEffect(() => {
    if (isAuthenticated) void navigate({ to: '/dashboard' });
  }, [isAuthenticated, navigate]);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
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
      const status = (err as { status?: number })?.status;
      if (status === 401 || status === 422) useSessionStore.getState().clearSession();
      setError(formatLoginError(err, selectedTenant.slug));
    } finally {
      setLoginLoading(false);
    }
  };

  const handleClearCache = () => {
    try {
      window.localStorage.removeItem('inventory_session');
      document.cookie.split('; ').forEach((cookie) => {
        const name = cookie.split('=')[0];
        if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`;
      });
    } catch {
      // El navegador puede bloquear el acceso a cookies/localStorage.
    }
    window.location.reload();
  };

  return (
    <main className="relative flex min-h-screen items-center justify-center overflow-hidden bg-[#eef0f3] px-5 py-10 text-text sm:px-8">
      <div className="absolute inset-x-0 top-0 h-1 bg-primary" aria-hidden="true" />
      <div className="absolute left-6 top-6 hidden items-center gap-3 text-text-primary sm:flex">
        <div className="flex size-9 items-center justify-center rounded bg-primary text-primary-foreground">
          <ShieldCheck className="size-4" aria-hidden="true" />
        </div>
        <div>
          <p className="text-sm font-semibold leading-none">{APP_NAME}</p>
          <p className="mt-1 text-[11px] uppercase tracking-[0.14em] text-text-muted">Workspace access</p>
        </div>
      </div>
      <div className="absolute right-6 top-7 hidden items-center gap-2 text-xs text-text-muted sm:flex">
        <span className="size-1.5 rounded-full bg-success" aria-hidden="true" />
        Acceso protegido
      </div>

      <div className="w-full max-w-[480px]">
        <header className="mb-6 text-center">
          <div className="mx-auto mb-4 flex size-11 items-center justify-center rounded bg-primary text-primary-foreground shadow-sm sm:hidden">
            <ShieldCheck className="size-5" aria-hidden="true" />
          </div>
          <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-primary">{APP_NAME}</p>
          <h1 className="mt-3 text-[2.15rem] font-semibold leading-tight tracking-[-0.03em] text-text-primary">Entra a tu espacio de trabajo</h1>
          <p className="mx-auto mt-3 max-w-[390px] text-sm leading-6 text-text-muted">
            Identifícate para continuar con tus operaciones de inventario y ventas.
          </p>
        </header>

        <form
          onSubmit={handleSubmit}
          className="relative rounded-lg border border-[#d9dce2] bg-surface p-6 shadow-[0_24px_70px_rgba(27,31,44,0.12)] sm:p-8"
          aria-label="Formulario de inicio de sesión"
        >
          <div className="mb-6 flex items-center justify-between border-b border-border pb-4">
            <div>
              <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-text-muted">Acceso de usuario</p>
              <p className="mt-1 text-sm text-text-primary">Usa tus credenciales de empresa</p>
            </div>
            <span className="rounded border border-primary/20 bg-primary/5 px-2 py-1 text-[10px] font-semibold uppercase tracking-[0.12em] text-primary">Seguro</span>
          </div>

          {error && (
            <div className="mb-5 space-y-3">
              <Alert variant="danger">
                <AlertTitle>No pudimos iniciar sesión</AlertTitle>
                <AlertDescription>{error}</AlertDescription>
              </Alert>
              <button
                type="button"
                onClick={handleClearCache}
                className="w-full rounded border border-border bg-surface px-3 py-2 text-xs text-text-muted hover:bg-bg"
                data-testid="login-clear-cache"
              >
                ¿Persiste el error? Limpiar caché y reintentar
              </button>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="email">Email de acceso</Label>
            <div className="relative">
              <Mail className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <Input
                id="email"
                type="email"
                autoComplete="email"
                autoFocus
                required
                placeholder="usuario@empresa.com"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                disabled={loginLoading}
                className="pl-8"
                data-testid="login-email"
              />
              {lookupLoading && <Spinner size="sm" className="absolute right-2 top-1/2 -translate-y-1/2" />}
            </div>
            <p className="text-xs text-text-muted">Buscaremos las empresas donde tienes acceso activo.</p>
          </div>

          <div className="mt-5 space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="tenant">Empresa</Label>
              {tenants.length > 1 && <span className="text-xs text-text-muted">{tenants.length} disponibles</span>}
            </div>
            <TenantPicker
              tenants={tenants}
              selected={selectedTenant}
              onChange={setSelectedTenant}
              email={email}
              disabled={loginLoading}
              lookupLoading={lookupLoading}
            />
          </div>

          <div className="mt-5 space-y-2">
            <Label htmlFor="password">Contraseña</Label>
            <div className="relative">
              <Lock className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted" aria-hidden="true" />
              <Input
                id="password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                required
                placeholder="Tu contraseña"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={loginLoading}
                className="pl-8 pr-10"
                data-testid="login-password"
              />
              <button
                type="button"
                onClick={() => setShowPassword((visible) => !visible)}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded p-1 text-text-muted transition-colors hover:bg-bg hover:text-text-primary focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              >
                {showPassword ? <EyeOff className="size-4" aria-hidden="true" /> : <Eye className="size-4" aria-hidden="true" />}
              </button>
            </div>
          </div>

          <Button
            type="submit"
            fullWidth
            className="mt-6 h-11"
            loading={loginLoading}
            disabled={!selectedTenant || !email || !password}
            data-testid="login-submit"
          >
            Entrar al sistema
          </Button>

          <div className="mt-5 border-t border-border pt-4 text-center">
            <p className="text-xs leading-5 text-text-muted">Si no puedes entrar, solicita acceso al administrador de tu empresa.</p>
            <Link to="/master/login" className="mt-2 inline-block text-xs font-medium text-primary hover:underline">
              Acceso de plataforma
            </Link>
          </div>
        </form>

        <p className="mt-5 text-center text-[11px] uppercase tracking-[0.12em] text-text-muted">Inventario · Punto de venta · Control operativo</p>
      </div>
    </main>
  );
}

function isValidEmail(value: string): boolean {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value.trim());
}

function formatLoginError(err: unknown, slug: string): string {
  const status = (err as { status?: number })?.status;
  const message = (err as Error)?.message ?? 'Error al iniciar sesión.';

  switch (status) {
    case 401:
      return 'Email o contraseña incorrectos.';
    case 403:
      return 'Tu cuenta no está activa. Contacta al administrador.';
    case 404:
      return `La empresa "${slug}" no existe. Selecciona otra empresa o limpia el caché.`;
    case 422:
      return /no pertenece|inactiv/i.test(message)
        ? `Tu email no tiene acceso a la empresa "${slug}". Verifica que seleccionaste la empresa correcta.`
        : message;
    case 429:
      return 'Demasiados intentos. Espera unos minutos antes de reintentar.';
    default:
      return message;
  }
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
    return <div className="rounded border border-dashed border-border bg-bg px-3 py-3 text-sm text-text-muted">Ingresa un email válido para buscar empresas.</div>;
  }
  if (lookupLoading) {
    return <div className="rounded border border-dashed border-border bg-bg px-3 py-3 text-sm text-text-muted">Buscando empresas...</div>;
  }
  if (tenants.length === 0) {
    return <div className="rounded border border-warning bg-warning/5 px-3 py-3 text-sm text-warning">No hay empresas activas para este email.</div>;
  }
  if (tenants.length === 1) {
    return (
      <div className="flex min-h-10 items-center gap-2 rounded border border-primary/40 bg-primary/5 px-3 text-sm">
        <Building2 className="size-4 text-primary" aria-hidden="true" />
        <span className="font-medium">{tenants[0]!.name}</span>
        <span className="text-xs text-text-muted">({tenants[0]!.slug})</span>
      </div>
    );
  }
  return (
    <select
      className={cn(
        'flex h-10 w-full rounded border border-border-strong bg-surface px-3 text-sm shadow-sm',
        'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-1',
        'disabled:cursor-not-allowed disabled:opacity-50',
      )}
      value={selected?.slug ?? ''}
      onChange={(event) => onChange(tenants.find((tenant) => tenant.slug === event.target.value) ?? null)}
      disabled={disabled}
      data-testid="login-tenant"
    >
      <option value="">— Selecciona una empresa —</option>
      {tenants.map((tenant) => <option key={tenant.id} value={tenant.slug}>{tenant.name} ({tenant.slug})</option>)}
    </select>
  );
}
