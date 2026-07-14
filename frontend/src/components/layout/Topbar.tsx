import { useNavigate } from '@tanstack/react-router';
import { Building2, ChevronDown, LogOut, RefreshCw, UserCircle } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import { useAuth } from '@/auth/useAuth';
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
  // Por simplicidad, el listado de tenants propios requiere un endpoint adicional.
  // En esta fase mostramos el tenant activo. En una fase posterior se agregara
  // un selector completo con busqueda via /api/auth/tenants.
  if (!user) return null;

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
          Cambiar empresa
          <ChevronDown className="size-3 opacity-60" aria-hidden="true" />
        </Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuLabel className="text-xs text-text-muted">
          Próximamente: selector completo
        </DropdownMenuLabel>
        <DropdownMenuSeparator />
        <DropdownMenuItem disabled>Mis empresas</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}