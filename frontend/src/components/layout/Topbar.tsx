import { useNavigate } from '@tanstack/react-router';
import { Building2, Check, ChevronDown, LogOut, RefreshCw, UserCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { useAuth, useAvailableTenants } from '@/auth/useAuth';
import { Button } from '@/components/ui/Button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/DropdownMenu';
import { useSessionStore } from '@/stores/session';
import { cn } from '@/lib/cn';

export function Topbar() {
  const user = useSessionStore((s) => s.user);
  const tenant = useSessionStore((s) => s.tenant);
  const roles = useSessionStore((s) => s.roles);
  const { signOut, refreshSession } = useAuth();
  const navigate = useNavigate();
  const [signingOut, setSigningOut] = useState(false);

  const handleSignOut = async () => {
    setSigningOut(true);
    try {
      await signOut();
      await navigate({ to: '/login' });
    } finally {
      setSigningOut(false);
    }
  };

  const handleRefresh = async () => {
    try {
      await refreshSession();
      toast.success('Permisos actualizados.');
    } catch {
      toast.error('No se pudieron refrescar los permisos.');
    }
  };

  return (
    <header className="flex h-14 items-center justify-between border-b border-border bg-surface px-4 sm:px-6">
      {/* Tenant activo */}
      <div className="flex items-center gap-2">
        <div className="flex size-8 items-center justify-center rounded-md bg-bg text-text-muted">
          <Building2 className="size-4" aria-hidden="true" />
        </div>
        <div>
          <p className="text-sm font-medium leading-tight">{tenant?.name ?? '—'}</p>
          <p className="text-xs text-text-muted leading-tight">{tenant?.slug ?? '—'}</p>
        </div>
      </div>

      {/* Acciones usuario */}
      <div className="flex items-center gap-2">
        <Button
          variant="ghost"
          size="icon-sm"
          onClick={handleRefresh}
          title="Refrescar permisos"
          aria-label="Refrescar permisos"
        >
          <RefreshCw className="size-4" aria-hidden="true" />
        </Button>

        <TenantSwitcher />

        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="sm"
              className="gap-2"
              data-testid="user-menu-trigger"
            >
              <UserCircle className="size-4" aria-hidden="true" />
              <span className="hidden sm:inline">{user?.name ?? 'Usuario'}</span>
              <ChevronDown className="size-3 opacity-60" aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuLabel>
              <div className="space-y-0.5">
                <p className="font-medium">{user?.name ?? '—'}</p>
                <p className="truncate text-xs text-text-muted">{user?.email ?? '—'}</p>
              </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuLabel className="text-xs text-text-muted">Roles</DropdownMenuLabel>
            <div className="px-2 pb-1 text-xs">
              {roles.length > 0 ? roles.join(', ') : <span className="text-text-muted">Sin rol</span>}
            </div>
            <DropdownMenuSeparator />
            <DropdownMenuItem
              onSelect={(e) => {
                e.preventDefault();
                void handleSignOut();
              }}
              disabled={signingOut}
              className={cn('text-danger focus:text-danger')}
            >
              <LogOut className="size-4" aria-hidden="true" />
              Cerrar sesión
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </div>
    </header>
  );
}

interface TenantSwitcherProps {
  onSwitch?: (slug: string) => void;
}

function TenantSwitcher(_props: TenantSwitcherProps = {}) {
  const user = useSessionStore((s) => s.user);
  const tenant = useSessionStore((s) => s.tenant);
  const { data: tenants, isLoading } = useAvailableTenants();
  const { switchTo } = useAuth();
  const [search, setSearch] = useState('');
  const [switching, setSwitching] = useState<string | null>(null);

  if (!user) return null;

  const filtered = (tenants ?? []).filter((t) => {
    const term = search.trim().toLowerCase();
    if (!term) return true;
    return t.name.toLowerCase().includes(term) || t.slug.toLowerCase().includes(term);
  });

  async function handleSwitch(slug: string) {
    if (slug === tenant?.slug) return;
    setSwitching(slug);
    try {
      await switchTo(slug);
      toast.success('Empresa cambiada.');
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al cambiar de empresa.';
      toast.error(msg);
    } finally {
      setSwitching(null);
    }
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button
          variant="outline"
          size="sm"
          className="hidden gap-2 md:inline-flex"
          data-testid="tenant-switcher"
        >
          <Building2 className="size-3.5" aria-hidden="true" />
          {tenant?.name ?? 'Empresa'}
          <ChevronDown className="size-3 opacity-60" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-64">
        <DropdownMenuLabel className="text-xs text-text-muted">Cambiar de empresa</DropdownMenuLabel>
        <div className="px-2 pb-2">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar empresa..."
            className="h-8 w-full rounded border border-border-strong bg-surface px-2 text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
            data-testid="tenant-switcher-search"
          />
        </div>
        <DropdownMenuSeparator />
        {isLoading ? (
          <DropdownMenuItem disabled>Cargando...</DropdownMenuItem>
        ) : filtered.length === 0 ? (
          <DropdownMenuItem disabled>Sin resultados.</DropdownMenuItem>
        ) : (
          filtered.map((t) => {
            const active = t.slug === tenant?.slug;
            return (
              <DropdownMenuItem
                key={t.id}
                onSelect={(e) => {
                  e.preventDefault();
                  void handleSwitch(t.slug);
                }}
                disabled={switching === t.slug}
                data-testid={`tenant-switcher-option-${t.slug}`}
              >
                <span className="flex w-full items-center justify-between gap-2">
                  <span className="flex-1 truncate">{t.name}</span>
                  {active && <Check className="size-3.5 text-success" aria-hidden="true" />}
                  {switching === t.slug && (
                    <span className="text-[10px] text-text-muted">cambiando...</span>
                  )}
                </span>
              </DropdownMenuItem>
            );
          })
        )}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}