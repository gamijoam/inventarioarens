import { useState } from 'react';
import { createFileRoute, redirect, useNavigate } from '@tanstack/react-router';
import { Building2, ChevronRight, LogOut } from 'lucide-react';

import { APP_MODE, APP_VISUAL_PROFILE } from '@/config/branding';
import { useAuth } from '@/auth/useAuth';
import { useSessionStore } from '@/stores/session';
import { isAuthDisabled } from '@/auth/devBypass';
import { getPostLoginRoute } from '@/auth/postLoginRoute';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/Alert';
import { Spinner } from '@/components/ui/Spinner';
import { Button } from '@/components/ui/Button';
import type { TenantOption } from '@/types/user';
import { cn } from '@/lib/cn';

export const Route = createFileRoute('/select-company')({
  beforeLoad: () => {
    if (isAuthDisabled()) {
      return;
    }

    const session = useSessionStore.getState();

    if (!session.user) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({ to: '/login' });
    }

    if (session.tenant) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({
        to: getPostLoginRoute(session.roles, Array.from(session.permissions)),
      });
    }

    if (session.pendingTenants.length === 0) {
      // eslint-disable-next-line @typescript-eslint/only-throw-error
      throw redirect({ to: '/login' });
    }
  },
  component: SelectCompanyPage,
});

export function SelectCompanyPage() {
  const isPos = APP_VISUAL_PROFILE.accent === 'pos';
  const user = useSessionStore((s) => s.user);
  const pendingTenants = useSessionStore((s) => s.pendingTenants);
  const { selectCompany, signOut } = useAuth();
  const navigate = useNavigate();

  const [selectingSlug, setSelectingSlug] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const handleSelect = async (tenant: TenantOption) => {
    setError(null);
    setSelectingSlug(tenant.slug);

    try {
      await selectCompany(tenant.slug);
      const session = useSessionStore.getState();
      await navigate({ to: getPostLoginRoute(session.roles, Array.from(session.permissions)) });
    } catch (err) {
      const message = (err as Error)?.message ?? 'No se pudo seleccionar la empresa.';
      setError(message);
      setSelectingSlug(null);
    }
  };

  const handleSignOut = async () => {
    await signOut();
    await navigate({ to: '/login' });
  };

  return (
    <main
      className={cn(
        'relative flex min-h-screen items-center justify-center overflow-hidden px-5 py-10 sm:px-8',
        'bg-[#e8ecf1]',
      )}
      data-app-mode={APP_MODE}
      data-testid="select-company-page"
    >
      <div
        className={cn('absolute inset-x-0 top-0 h-1', isPos ? 'bg-emerald-400' : 'bg-primary')}
        aria-hidden="true"
      />

      <div className="w-full max-w-[500px]">
        <div
          className={cn(
            'relative rounded-2xl border bg-white p-8 shadow-[0_24px_60px_rgba(27,31,44,0.12)] sm:p-10',
            isPos ? 'border-emerald-200' : 'border-[#e2e5ea]',
          )}
        >
          {/* Header */}
          <header className="mb-6 text-center">
            <div
              className={cn(
                'mx-auto flex size-14 items-center justify-center rounded-xl text-white shadow-sm overflow-hidden p-1',
                isPos ? 'bg-emerald-500' : 'bg-primary',
              )}
              aria-hidden="true"
            >
              <Building2 className="size-7" />
            </div>
            <h1 className="text-text-primary mt-4 text-xl font-bold tracking-tight">
              Selecciona tu empresa
            </h1>
            <p className="text-text-muted mt-1 text-sm">
              Hola, <span className="font-semibold text-text-primary">{user?.name || user?.email}</span>. Tienes acceso a las siguientes empresas:
            </p>
          </header>

          {error && (
            <div className="mb-5">
              <Alert variant="danger">
                <AlertTitle>No pudimos cambiar de empresa</AlertTitle>
                <AlertDescription>{error}</AlertDescription>
              </Alert>
            </div>
          )}

          {/* Company Cards List */}
          <div className="space-y-3" role="list" aria-label="Empresas disponibles" data-testid="company-list">
            {pendingTenants.map((tenant) => {
              const isLoadingThis = selectingSlug === tenant.slug;
              const isDisabled = Boolean(selectingSlug);

              return (
                <button
                  key={tenant.id}
                  type="button"
                  onClick={() => void handleSelect(tenant)}
                  disabled={isDisabled}
                  className={cn(
                    'group relative flex w-full items-center justify-between gap-4 rounded-xl border p-4 text-left transition-all',
                    'hover:border-primary hover:shadow-md focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                    isLoadingThis
                      ? 'border-primary bg-primary/5'
                      : 'border-[#e2e5ea] bg-white hover:bg-slate-50/50',
                    isDisabled && !isLoadingThis && 'opacity-50 cursor-not-allowed',
                  )}
                  data-testid={`company-item-${tenant.slug}`}
                >
                  <div className="flex items-center gap-3.5 min-w-0">
                    <div
                      className={cn(
                        'flex size-11 shrink-0 items-center justify-center rounded-lg border border-[#e2e5ea] bg-slate-50 text-text-muted p-1',
                        'group-hover:border-primary/40 group-hover:text-primary transition-colors',
                      )}
                    >
                      {tenant.logo_url ? (
                        <img
                          src={tenant.logo_url}
                          alt={tenant.name}
                          className="size-full object-contain"
                        />
                      ) : (
                        <Building2 className="size-5" />
                      )}
                    </div>
                    <div className="min-w-0 truncate">
                      <div className="text-text-primary font-semibold text-sm truncate group-hover:text-primary transition-colors">
                        {tenant.name}
                      </div>
                      <div className="text-text-muted text-xs truncate">
                        ID: <span className="font-mono text-[11px]">{tenant.slug}</span>
                      </div>
                    </div>
                  </div>

                  <div className="shrink-0 flex items-center gap-2">
                    {isLoadingThis ? (
                      <Spinner size="sm" className="text-primary" />
                    ) : (
                      <ChevronRight className="size-5 text-text-muted group-hover:text-primary group-hover:translate-x-0.5 transition-all" />
                    )}
                  </div>
                </button>
              );
            })}
          </div>

          {/* Footer Actions */}
          <div className="mt-8 border-t border-[#f0f2f5] pt-5 text-center">
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => void handleSignOut()}
              disabled={Boolean(selectingSlug)}
              className="text-text-muted hover:text-text-primary text-xs gap-1.5"
              data-testid="select-company-signout"
            >
              <LogOut className="size-3.5" />
              Iniciar sesión con otra cuenta
            </Button>
          </div>
        </div>
      </div>
    </main>
  );
}
