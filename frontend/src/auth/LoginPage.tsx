import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import { Building2, Eye, EyeOff, Lock, Mail, ShieldCheck } from 'lucide-react';

import { APP_DEFINITION, APP_MODE, APP_NAME } from '@/config/branding';
import { getLoginPresentation } from '@/auth/loginPresentation';
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
  const presentation = getLoginPresentation(APP_MODE);
  const isPos = presentation.theme === 'pos';
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
    <main
      className={cn(
        'relative flex min-h-screen items-center justify-center overflow-hidden px-5 py-10 sm:px-8',
        isPos ? 'bg-[#101918] text-white' : 'text-text bg-[#eef0f3]',
      )}
      data-app-mode={APP_MODE}
      data-testid="login-page"
    >
      <div
        className={cn('absolute inset-x-0 top-0 h-1', isPos ? 'bg-emerald-400' : 'bg-primary')}
        aria-hidden="true"
      />
      <div
        className={cn(
          'absolute top-6 left-6 hidden items-center gap-3 sm:flex',
          isPos ? 'text-white' : 'text-text-primary',
        )}
      >
        <div
          className={cn(
            'text-primary-foreground flex size-9 items-center justify-center rounded',
            isPos ? 'bg-emerald-400' : 'bg-primary',
          )}
        >
          <ShieldCheck className="size-4" aria-hidden="true" />
        </div>
        <div>
          <p className="text-sm leading-none font-semibold">{APP_NAME}</p>
          <p
            className={cn(
              'mt-1 text-[11px] tracking-[0.14em] uppercase',
              isPos ? 'text-emerald-200' : 'text-text-muted',
            )}
          >
            {APP_DEFINITION.tagline}
          </p>
        </div>
      </div>
      <div
        className={cn(
          'absolute top-7 right-6 hidden items-center gap-2 text-xs sm:flex',
          isPos ? 'text-emerald-200' : 'text-text-muted',
        )}
      >
        <span
          className={cn('size-1.5 rounded-full', isPos ? 'bg-emerald-400' : 'bg-success')}
          aria-hidden="true"
        />
        {isPos ? 'Terminal local' : 'Acceso protegido'}
      </div>

      <div className="w-full max-w-[480px]">
        <header className="mb-6 text-center">
          <div className="bg-primary text-primary-foreground mx-auto mb-4 flex size-11 items-center justify-center rounded shadow-sm sm:hidden">
            <ShieldCheck className="size-5" aria-hidden="true" />
          </div>
          <p
            className={cn(
              'text-[11px] font-semibold tracking-[0.18em] uppercase',
              isPos ? 'text-emerald-300' : 'text-primary',
            )}
          >
            {presentation.eyebrow}
          </p>
          <h1
            className={cn(
              'mt-3 text-[2.15rem] leading-tight font-semibold tracking-[-0.03em]',
              isPos ? 'text-white' : 'text-text-primary',
            )}
          >
            {presentation.title}
          </h1>
          <p
            className={cn(
              'mx-auto mt-3 max-w-[390px] text-sm leading-6',
              isPos ? 'text-slate-300' : 'text-text-muted',
            )}
          >
            {presentation.description}
          </p>
        </header>

        <form
          onSubmit={handleSubmit}
          className={cn(
            'bg-surface relative rounded-lg border p-6 shadow-[0_24px_70px_rgba(27,31,44,0.12)] sm:p-8',
            isPos
              ? 'border-emerald-300/40 shadow-[0_24px_80px_rgba(16,185,129,0.14)]'
              : 'border-[#d9dce2]',
          )}
          aria-label="Formulario de inicio de sesión"
        >
          <div className="border-border mb-6 flex items-center justify-between border-b pb-4">
            <div>
              <p className="text-text-muted text-[11px] font-semibold tracking-[0.16em] uppercase">
                {presentation.formEyebrow}
              </p>
              <p className="text-text-primary mt-1 text-sm">{presentation.formTitle}</p>
            </div>
            <span
              className={cn(
                'rounded border px-2 py-1 text-[10px] font-semibold tracking-[0.12em] uppercase',
                isPos
                  ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700'
                  : 'border-primary/20 bg-primary/5 text-primary',
              )}
            >
              {isPos ? 'Offline-first' : 'Seguro'}
            </span>
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
                className="border-border bg-surface text-text-muted hover:bg-bg w-full rounded border px-3 py-2 text-xs"
                data-testid="login-clear-cache"
              >
                ¿Persiste el error? Limpiar caché y reintentar
              </button>
            </div>
          )}

          <div className="space-y-2">
            <Label htmlFor="email">Email de acceso</Label>
            <div className="relative">
              <Mail
                className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                aria-hidden="true"
              />
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
              {lookupLoading && (
                <Spinner size="sm" className="absolute top-1/2 right-2 -translate-y-1/2" />
              )}
            </div>
            <p className="text-text-muted text-xs">
              Buscaremos las empresas donde tienes acceso activo.
            </p>
          </div>

          <div className="mt-5 space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="tenant">Empresa</Label>
              {tenants.length > 1 && (
                <span className="text-text-muted text-xs">{tenants.length} disponibles</span>
              )}
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
              <Lock
                className="text-text-muted pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                aria-hidden="true"
              />
              <Input
                id="password"
                type={showPassword ? 'text' : 'password'}
                autoComplete="current-password"
                required
                placeholder="Tu contraseña"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                disabled={loginLoading}
                className="pr-10 pl-8"
                data-testid="login-password"
              />
              <button
                type="button"
                onClick={() => setShowPassword((visible) => !visible)}
                className="text-text-muted hover:bg-bg hover:text-text-primary focus-visible:ring-primary absolute top-1/2 right-2.5 -translate-y-1/2 rounded p-1 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              >
                {showPassword ? (
                  <EyeOff className="size-4" aria-hidden="true" />
                ) : (
                  <Eye className="size-4" aria-hidden="true" />
                )}
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
            {presentation.submitLabel}
          </Button>

          <div className="border-border mt-5 border-t pt-4 text-center">
            <p className="text-text-muted text-xs leading-5">
              Si no puedes entrar, solicita acceso al administrador de tu empresa.
            </p>
            <Link
              to="/master/login"
              className="text-primary mt-2 inline-block text-xs font-medium hover:underline"
            >
              Acceso de plataforma
            </Link>
          </div>
        </form>

        <p
          className={cn(
            'mt-5 text-center text-[11px] tracking-[0.12em] uppercase',
            isPos ? 'text-slate-400' : 'text-text-muted',
          )}
        >
          {presentation.footer}
        </p>
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
      return 'Demasiados intentos de autenticación. Espera 1 minuto antes de reintentar.';
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

function TenantPicker({
  tenants,
  selected,
  onChange,
  email,
  disabled,
  lookupLoading,
}: TenantPickerProps) {
  if (!isValidEmail(email)) {
    return (
      <div className="border-border bg-bg text-text-muted rounded border border-dashed px-3 py-3 text-sm">
        Ingresa un email válido para buscar empresas.
      </div>
    );
  }
  if (lookupLoading) {
    return (
      <div className="border-border bg-bg text-text-muted rounded border border-dashed px-3 py-3 text-sm">
        Buscando empresas...
      </div>
    );
  }
  if (tenants.length === 0) {
    return (
      <div className="border-warning bg-warning/5 text-warning rounded border px-3 py-3 text-sm">
        No hay empresas activas para este email.
      </div>
    );
  }
  if (tenants.length === 1) {
    return (
      <div className="border-primary/40 bg-primary/5 flex min-h-10 items-center gap-2 rounded border px-3 text-sm">
        <Building2 className="text-primary size-4" aria-hidden="true" />
        <span className="font-medium">{tenants[0]!.name}</span>
        <span className="text-text-muted text-xs">({tenants[0]!.slug})</span>
      </div>
    );
  }
  return (
    <select
      className={cn(
        'border-border-strong bg-surface flex h-10 w-full rounded border px-3 text-sm shadow-sm',
        'focus-visible:ring-primary focus-visible:ring-2 focus-visible:ring-offset-1 focus-visible:outline-none',
        'disabled:cursor-not-allowed disabled:opacity-50',
      )}
      value={selected?.slug ?? ''}
      onChange={(event) =>
        onChange(tenants.find((tenant) => tenant.slug === event.target.value) ?? null)
      }
      disabled={disabled}
      data-testid="login-tenant"
    >
      <option value="">— Selecciona una empresa —</option>
      {tenants.map((tenant) => (
        <option key={tenant.id} value={tenant.slug}>
          {tenant.name} ({tenant.slug})
        </option>
      ))}
    </select>
  );
}
