import { useNavigate } from '@tanstack/react-router';
import {
  Building2,
  Check,
  ChevronDown,
  ExternalLink,
  Loader2,
  LogOut,
  RefreshCw,
  Search,
  TrendingUp,
  UserCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

import { useAuth, useAvailableTenants } from '@/auth/useAuth';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
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
import { PERMISSIONS } from '@/permissions/constants';
import {
  quoteProductForPos,
  useCurrentExchangeRatesForPos,
  usePosProductsDebounced,
  usePriceListsForPos,
  type CurrentExchangeRate,
} from '@/features/pos/api';
import type { Product, PriceList } from '@/features/inventory-center/schemas';
import { IntercompanyNotificationBell } from '@/features/inventory-transfer-notifications/IntercompanyNotificationBell';

const EMPTY_PRICE_LISTS: PriceList[] = [];

export function Topbar() {
  const user = useSessionStore((s) => s.user);
  const tenant = useSessionStore((s) => s.tenant);
  const roles = useSessionStore((s) => s.roles);
  const permissions = useSessionStore((s) => s.permissions);
  const { signOut, refreshSession } = useAuth();
  const navigate = useNavigate();
  const [signingOut, setSigningOut] = useState(false);
  const grantedPermissions = permissions ?? new Set<string>();
  const canViewProducts = grantedPermissions.has(PERMISSIONS.PRODUCTS_VIEW);
  const canViewCurrency = grantedPermissions.has(PERMISSIONS.CURRENCY_VIEW);
  const canManageCurrency = grantedPermissions.has(PERMISSIONS.CURRENCY_MANAGE);
  const canViewIntercompany = grantedPermissions.has(PERMISSIONS.INVENTORY_TRANSFER_REQUESTS_VIEW);

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
    <header className="border-border bg-surface flex h-14 items-center justify-between border-b px-4 sm:px-6">
      {/* Tenant activo */}
      <div className="flex items-center gap-2">
        <div className="bg-bg text-text-muted flex size-8 items-center justify-center rounded-md">
          <Building2 className="size-4" aria-hidden="true" />
        </div>
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <p className="text-sm leading-tight font-medium">{tenant?.name ?? '—'}</p>
            {tenant && (
              <Badge
                variant={tenant.is_group ? 'primary' : tenant.parent_id ? 'info' : 'outline'}
                className="text-[10px]"
                data-testid="tenant-context-badge"
              >
                {tenant.is_group ? 'Grupo' : tenant.parent_id ? 'Sucursal' : 'Empresa'}
              </Badge>
            )}
          </div>
          <p className="text-text-muted text-xs leading-tight">{tenant?.slug ?? '—'}</p>
        </div>
      </div>

      {/* Acciones usuario */}
      <div className="flex min-w-0 items-center gap-2">
        {canViewProducts && <GlobalProductSearch />}
        {canViewCurrency && (
          <CurrentRateIndicator
            canManage={canManageCurrency}
            onManage={() => void navigate({ to: '/inventory/currency' })}
          />
        )}
        {canViewIntercompany && <IntercompanyNotificationBell />}
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
            <Button variant="ghost" size="sm" className="gap-2" data-testid="user-menu-trigger">
              <UserCircle className="size-4" aria-hidden="true" />
              <span className="hidden sm:inline">{user?.name ?? 'Usuario'}</span>
              <ChevronDown className="size-3 opacity-60" aria-hidden="true" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuLabel>
              <div className="space-y-0.5">
                <p className="font-medium">{user?.name ?? '—'}</p>
                <p className="text-text-muted truncate text-xs">{user?.email ?? '—'}</p>
              </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuLabel className="text-text-muted text-xs">Roles</DropdownMenuLabel>
            <div className="px-2 pb-1 text-xs">
              {roles.length > 0 ? (
                roles.join(', ')
              ) : (
                <span className="text-text-muted">Sin rol</span>
              )}
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

function GlobalProductSearch() {
  const containerRef = useRef<HTMLDivElement | null>(null);
  const [search, setSearch] = useState('');
  const [open, setOpen] = useState(false);
  const [selected, setSelected] = useState<Product | null>(null);
  const [quotes, setQuotes] = useState<
    { list: PriceList; price: number | null; currency: string; ves: number | null }[]
  >([]);
  const [loadingQuotes, setLoadingQuotes] = useState(false);
  const { data: page, isFetching } = usePosProductsDebounced(search, null, {
    enabled: open && search.trim().length >= 2,
  });
  const { data: priceListsData } = usePriceListsForPos();
  const priceLists = priceListsData ?? EMPTY_PRICE_LISTS;

  useEffect(() => {
    if (!selected || priceLists.length === 0) {
      setQuotes([]);
      return;
    }

    let cancelled = false;
    setLoadingQuotes(true);
    void Promise.all(
      priceLists.map(async (list) => {
        try {
          const quote = await quoteProductForPos(selected.id, list.id);
          return {
            list,
            price: quote.sale_price,
            currency: quote.sale_currency,
            ves: quote.price_ves,
          };
        } catch {
          return { list, price: null, currency: 'USD', ves: null };
        }
      }),
    )
      .then((result) => {
        if (!cancelled) setQuotes(result);
      })
      .finally(() => {
        if (!cancelled) setLoadingQuotes(false);
      });

    return () => {
      cancelled = true;
    };
  }, [priceLists, selected]);

  const results = page?.data?.slice(0, 6) ?? [];

  useEffect(() => {
    function handlePointerDown(event: PointerEvent): void {
      if (!containerRef.current?.contains(event.target as Node)) setOpen(false);
    }

    function handleKeyDown(event: KeyboardEvent): void {
      if (event.key === 'Escape') setOpen(false);
    }

    document.addEventListener('pointerdown', handlePointerDown);
    document.addEventListener('keydown', handleKeyDown);
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, []);

  return (
    <div ref={containerRef} className="relative hidden min-w-0 lg:block">
      <div className="border-border bg-bg focus-within:border-primary focus-within:ring-primary/20 flex h-9 w-[min(30vw,360px)] items-center gap-2 rounded-md border px-2 focus-within:ring-2">
        <Search className="text-text-muted size-4 shrink-0" aria-hidden="true" />
        <input
          value={search}
          onChange={(event) => {
            setSearch(event.target.value);
            setSelected(null);
            setOpen(true);
          }}
          onFocus={() => setOpen(true)}
          placeholder="Consultar producto..."
          className="placeholder:text-text-muted min-w-0 flex-1 bg-transparent text-sm outline-none"
          aria-label="Consultar producto"
        />
        {isFetching && (
          <Loader2 className="text-text-muted size-4 animate-spin" aria-hidden="true" />
        )}
      </div>
      {open && search.trim().length >= 2 && (
        <div className="border-border bg-surface absolute top-11 right-0 z-50 w-[min(92vw,420px)] overflow-hidden rounded-lg border shadow-xl">
          {!selected ? (
            results.length > 0 ? (
              results.map((product) => (
                <button
                  key={product.id}
                  type="button"
                  className="border-border hover:bg-bg flex w-full items-center justify-between gap-3 border-b px-3 py-2 text-left last:border-0"
                  onClick={() => setSelected(product)}
                >
                  <span className="min-w-0">
                    <span className="block truncate text-sm font-medium">{product.name}</span>
                    <span className="text-text-muted block text-xs">
                      {product.sku ?? 'Sin SKU'} · Stock{' '}
                      {Number(product.available_stock ?? 0).toLocaleString('es-VE')}
                    </span>
                  </span>
                  <span className="shrink-0 text-sm font-semibold">
                    ${Number(product.base_price ?? 0).toFixed(2)}
                  </span>
                </button>
              ))
            ) : (
              <p className="text-text-muted px-3 py-4 text-sm">Sin productos encontrados.</p>
            )
          ) : (
            <div className="p-3">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-semibold">{selected.name}</p>
                  <p className="text-text-muted text-xs">
                    {selected.sku ?? 'Sin SKU'} · Stock{' '}
                    {Number(selected.available_stock ?? 0).toLocaleString('es-VE')}
                  </p>
                </div>
                <button
                  type="button"
                  className="text-primary text-xs"
                  onClick={() => setSelected(null)}
                >
                  Volver
                </button>
              </div>
              <div className="mt-3 space-y-1.5">
                <div className="bg-bg flex justify-between rounded px-2 py-1.5 text-sm">
                  <span>Precio base</span>
                  <strong>${Number(selected.base_price ?? 0).toFixed(2)}</strong>
                </div>
                {loadingQuotes ? (
                  <p className="text-text-muted text-xs">Consultando listas de precio...</p>
                ) : (
                  quotes.map((quote) => (
                    <div
                      key={quote.list.id}
                      className="border-border flex justify-between rounded border px-2 py-1.5 text-sm"
                    >
                      <span>{quote.list.name}</span>
                      {quote.price === null ? (
                        <strong className="text-text-muted font-normal">
                          Sin precio configurado
                        </strong>
                      ) : (
                        <strong>
                          {quote.currency === 'VES'
                            ? `Bs ${quote.price.toLocaleString('es-VE', { minimumFractionDigits: 2 })}`
                            : `$${quote.price.toFixed(2)}`}
                          {quote.ves !== null && quote.currency !== 'VES'
                            ? ` · Bs ${quote.ves.toLocaleString('es-VE', { minimumFractionDigits: 2 })}`
                            : ''}
                        </strong>
                      )}
                    </div>
                  ))
                )}
              </div>
            </div>
          )}
          <button
            type="button"
            className="border-border bg-bg text-text-muted hover:text-primary flex w-full items-center justify-center gap-1 border-t px-3 py-2 text-xs"
            onClick={() => setOpen(false)}
          >
            Cerrar consulta
          </button>
        </div>
      )}
    </div>
  );
}

function CurrentRateIndicator({
  canManage,
  onManage,
}: {
  canManage: boolean;
  onManage: () => void;
}) {
  const { data: rates = [] } = useCurrentExchangeRatesForPos();

  return <RateIndicator rates={rates} canManage={canManage} onManage={onManage} />;
}

function RateIndicator({
  rates,
  canManage,
  onManage,
}: {
  rates: CurrentExchangeRate[];
  canManage: boolean;
  onManage: () => void;
}) {
  const rate = rates.find((item) => item.is_active !== false) ?? rates[0];
  if (!rate) return null;

  const label = `${rate.exchange_rate_type_code ?? 'Tasa'} ${Number(rate.rate).toLocaleString('es-VE', { maximumFractionDigits: 2 })}`;
  return canManage ? (
    <Button
      variant="outline"
      size="sm"
      className="hidden gap-1.5 xl:inline-flex"
      onClick={onManage}
      title="Gestionar tasa del día"
    >
      <TrendingUp className="text-success size-3.5" aria-hidden="true" /> {label}{' '}
      <ExternalLink className="size-3 opacity-50" aria-hidden="true" />
    </Button>
  ) : (
    <Badge variant="success" className="hidden xl:inline-flex" title="Tasa vigente del día">
      <TrendingUp className="mr-1 size-3" aria-hidden="true" /> {label}
    </Badge>
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
        <DropdownMenuLabel className="text-text-muted text-xs">
          Cambiar de empresa
        </DropdownMenuLabel>
        <div className="px-2 pb-2">
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Buscar empresa..."
            className="border-border-strong bg-surface focus-visible:ring-primary h-8 w-full rounded border px-2 text-sm focus:outline-none focus-visible:ring-2"
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
                  {active && <Check className="text-success size-3.5" aria-hidden="true" />}
                  {switching === t.slug && (
                    <span className="text-text-muted text-[10px]">cambiando...</span>
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
