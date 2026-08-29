import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate } from '@tanstack/react-router';
import { Building2, Eye, EyeOff, Lock, Mail } from 'lucide-react';

import { APP_MODE, APP_VISUAL_PROFILE } from '@/config/branding';
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
import { getPostLoginRoute } from '@/auth/postLoginRoute';

const DEBOUNCE_MS = 500;

export function LoginPage() {
  const presentation = getLoginPresentation(APP_MODE);
  const isPos = APP_VISUAL_PROFILE.accent === 'pos';
  const { signIn, isAuthenticated } = useAuth();
  const navigate = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenants, setTenants] = useState<TenantOption[]>([]);
  const [selectedTenant, setSelectedTenant] = useState<TenantOption | null>(null);
  const [lookupLoading, setLookupLoading] = useState(false);
  const [loginLoading, setLoginLoading] = useState(false);
  const [showPassword, setShowPassword] = useState(false);
  const [forgotOpen, setForgotOpen] = useState(false);
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
    if (isAuthenticated) {
      const session = useSessionStore.getState();
      void navigate({ to: getPostLoginRoute(session.roles, Array.from(session.permissions)) });
    }
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
      const session = useSessionStore.getState();
      await navigate({ to: getPostLoginRoute(session.roles, Array.from(session.permissions)) });
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
        'bg-[#e8ecf1]',
      )}
      data-app-mode={APP_MODE}
      data-testid="login-page"
    >
      <div
        className={cn('absolute inset-x-0 top-0 h-1', isPos ? 'bg-emerald-400' : 'bg-primary')}
        aria-hidden="true"
      />

      <div className="w-full max-w-[440px]">
        <form
          onSubmit={handleSubmit}
          className={cn(
            'relative rounded-2xl border bg-white p-8 shadow-[0_24px_60px_rgba(27,31,44,0.12)] sm:p-10',
            isPos ? 'border-emerald-200' : 'border-[#e2e5ea]',
          )}
          aria-label="Formulario de inicio de sesión"
        >
          {/* Logo corporativo */}
          <header className="mb-8 text-center">
            <div
              className={cn(
                'mx-auto flex size-14 items-center justify-center rounded-xl text-white shadow-sm',
                isPos ? 'bg-emerald-500' : 'bg-primary',
              )}
              aria-hidden="true"
            >
              <span className="text-xl font-bold tracking-wide">{APP_VISUAL_PROFILE.logoMark}</span>
            </div>
            <p
              className={cn(
                'mt-3 text-sm font-semibold tracking-[0.22em]',
                isPos ? 'text-emerald-600' : 'text-primary',
              )}
            >
              {APP_VISUAL_PROFILE.productLabel}
            </p>
          </header>

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

          {/* Email */}
          <div className="space-y-1.5">
            <Label htmlFor="email" className="text-text-primary text-sm font-medium">
              Email
            </Label>
            <div className="relative">
              <Mail
                className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
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
                className="h-11 rounded-lg border bg-white px-4 pl-9 text-sm shadow-sm"
                data-testid="login-email"
              />
              {lookupLoading && (
                <Spinner size="sm" className="absolute top-1/2 right-3 -translate-y-1/2" />
              )}
            </div>
          </div>

          {/* Empresa (selector discreto, justo encima de la contrasena) */}
          <div className="mt-5 space-y-1.5">
            <div className="flex items-center justify-between">
              <Label htmlFor="tenant" className="text-text-primary text-sm font-medium">
                Empresa
              </Label>
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

          {/* Contrasena */}
          <div className="mt-5 space-y-1.5">
            <Label htmlFor="password" className="text-text-primary text-sm font-medium">
              Contraseña
            </Label>
            <div className="relative">
              <Lock
                className="text-text-muted pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
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
                className="h-11 rounded-lg border bg-white px-4 pr-11 pl-9 text-sm shadow-sm"
                data-testid="login-password"
              />
              <button
                type="button"
                onClick={() => setShowPassword((visible) => !visible)}
                className="text-text-muted hover:bg-bg hover:text-text-primary focus-visible:ring-primary absolute top-1/2 right-3 -translate-y-1/2 rounded p-1 transition-colors focus-visible:ring-2 focus-visible:outline-none"
                aria-label={showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'}
              >
                {showPassword ? (
                  <EyeOff className="size-4" aria-hidden="true" />
                ) : (
                  <Eye className="size-4" aria-hidden="true" />
                )}
              </button>
            </div>
            <div className="flex justify-end pt-0.5">
              <button
                type="button"
                onClick={() => setForgotOpen((open) => !open)}
                className="text-primary text-xs font-medium hover:underline"
                data-testid="login-forgot"
              >
                Forgot Password?
              </button>
            </div>
            {forgotOpen && (
              <p className="text-text-muted pt-0.5 text-xs" data-testid="login-forgot-help">
                Contacta al administrador de tu empresa para restablecer tu contraseña.
              </p>
            )}
          </div>

          <Button
            type="submit"
            fullWidth
            className="mt-6 h-12 rounded-lg text-sm font-semibold tracking-[0.14em]"
            loading={loginLoading}
            disabled={!selectedTenant || !email || !password}
            data-testid="login-submit"
          >
            LOGIN
          </Button>

          <div className="mt-6 pt-4 text-center">
            <p className="text-text-muted text-xs leading-5">
              Si no puedes entrar, solicita acceso a tu administrador.
            </p>
            <Link
              to="/master/login"
              className="text-text-muted hover:text-primary mt-2 inline-block text-[11px] hover:underline"
            >
              Acceso de plataforma
            </Link>
          </div>
        </form>

        <p
          className={cn(
            'mt-5 text-center text-[11px] tracking-[0.12em] uppercase',
            'text-text-muted',
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
      <div className="bg-surface text-text-muted rounded-lg border border-dashed px-3 py-2.5 text-xs">
        Ingresa un email válido para buscar empresas.
      </div>
    );
  }
  if (lookupLoading) {
    return (
      <div className="bg-surface text-text-muted rounded-lg border border-dashed px-3 py-2.5 text-xs">
        Buscando empresas...
      </div>
    );
  }
  if (tenants.length === 0) {
    return (
      <div className="border-warning bg-warning/5 text-warning rounded-lg border px-3 py-2.5 text-xs">
        No hay empresas activas para este email.
      </div>
    );
  }
  if (tenants.length === 1) {
    return (
      <div className="border-primary/40 bg-primary/5 flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm">
        <Building2 className="text-primary size-4" aria-hidden="true" />
        <span className="font-medium">{tenants[0]!.name}</span>
        <span className="text-text-muted text-xs">({tenants[0]!.slug})</span>
      </div>
    );
  }
  return (
    <select
      className={cn(
        'text-text-primary flex h-11 w-full rounded-lg border border-[#e2e5ea] bg-white px-3 text-sm shadow-sm',
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
