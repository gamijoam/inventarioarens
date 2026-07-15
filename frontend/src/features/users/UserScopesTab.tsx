/**
 * UserScopesTab: gestion de scopes por usuario (branches, warehouses,
 * customer-groups, vendor-of).
 *
 * Backend: PUT /api/tenants/{tenant}/users/{user}/scopes
 *   Body: { branches: [], warehouses: [], customer_groups: [], vendor_of: [] }
 *   (reemplaza TODOS los scopes del user; idempotente)
 *
 * Cada tipo de scope tiene su propia pestana. Los catalogos se cargan
 * una sola vez (staleTime 5min) y se filtran localmente.
 */
import { useEffect, useState } from 'react';
import { Building2, Package, Save, Truck, Users as UsersIcon } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/Tabs';

import {
  useReplaceAllScopes,
  useScopesCatalog,
  useUserScopes,
} from './api';

interface UserScopesTabProps {
  userId: number;
}

type ScopeKey = 'branches' | 'warehouses' | 'customerGroups' | 'vendorOf';

const SCOPE_LABELS: Record<ScopeKey, string> = {
  branches: 'Sucursales',
  warehouses: 'Almacenes',
  customerGroups: 'Grupos de clientes',
  vendorOf: 'Proveedores',
};

export function UserScopesTab({ userId }: UserScopesTabProps) {
  const { data, isLoading } = useUserScopes(userId);
  const replace = useReplaceAllScopes();
  const catalog = useScopesCatalog();

  const [branches, setBranches] = useState<number[]>([]);
  const [warehouses, setWarehouses] = useState<number[]>([]);
  const [customerGroups, setCustomerGroups] = useState<number[]>([]);
  const [vendorOf, setVendorOf] = useState<number[]>([]);
  const [tab, setTab] = useState<ScopeKey>('branches');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (!data) return;
    setBranches(data.branches ?? []);
    setWarehouses(data.warehouses ?? []);
    setCustomerGroups(data.customer_groups ?? []);
    setVendorOf(data.vendor_of ?? []);
  }, [data]);

  function toggle(key: ScopeKey, id: number) {
    const setters: Record<ScopeKey, React.Dispatch<React.SetStateAction<number[]>>> = {
      branches: setBranches,
      warehouses: setWarehouses,
      customerGroups: setCustomerGroups,
      vendorOf: setVendorOf,
    };
    setters[key]((prev) => prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]);
  }

  const isDirty = !!data && (
    JSON.stringify(branches) !== JSON.stringify(data.branches ?? []) ||
    JSON.stringify(warehouses) !== JSON.stringify(data.warehouses ?? []) ||
    JSON.stringify(customerGroups) !== JSON.stringify(data.customerGroups ?? []) ||
    JSON.stringify(vendorOf) !== JSON.stringify(data.vendor_of ?? [])
  );

  async function save() {
    setSaving(true);
    try {
      await replace.mutateAsync({
        userId,
        values: { branches, warehouses, customer_groups: customerGroups, vendor_of: vendorOf },
      });
      toast.success('Scopes guardados.');
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al guardar scopes.';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  }

  if (isLoading || catalog.isLoading) {
    return <Skeleton className="h-64 w-full" />;
  }

  const items: Record<ScopeKey, { id: number; label: string }[]> = {
    branches: catalog.data?.branches.map((b) => ({ id: b.id, label: `${b.code} - ${b.name}` })) ?? [],
    warehouses: catalog.data?.warehouses.map((w) => ({ id: w.id, label: `${w.code} - ${w.name}` })) ?? [],
    customerGroups: catalog.data?.customerGroups.map((c) => ({ id: c.id, label: `${c.code} - ${c.name}` })) ?? [],
    vendorOf: catalog.data?.vendors.map((v) => ({ id: v.id, label: `${v.tax_id ?? '(sin rif)'} - ${v.name}` })) ?? [],
  };

  const selected: Record<ScopeKey, number[]> = {
    branches,
    warehouses,
    customerGroups,
    vendorOf,
  };

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-2">
        <div>
          <CardTitle>Scopes del usuario</CardTitle>
          <CardDescription>
            Restringe a que branches, almacenes, grupos de clientes y proveedores ve
            este usuario. Si esta vacio, el scope del rol aplica.
          </CardDescription>
        </div>
        <Button onClick={save} loading={saving} disabled={!isDirty}>
          <Save className="size-4" /> Guardar scopes
        </Button>
      </CardHeader>
      <CardContent>
        <Tabs value={tab} onValueChange={(v) => setTab(v as ScopeKey)}>
          <TabsList>
            <TabsTrigger value="branches">
              <UsersIcon className="size-3.5" aria-hidden="true" /> Sucursales
              <Badge variant="info" className="ml-1 text-[10px]">{branches.length}</Badge>
            </TabsTrigger>
            <TabsTrigger value="warehouses">
              <Package className="size-3.5" aria-hidden="true" /> Almacenes
              <Badge variant="info" className="ml-1 text-[10px]">{warehouses.length}</Badge>
            </TabsTrigger>
            <TabsTrigger value="customerGroups">
              <Building2 className="size-3.5" aria-hidden="true" /> Grupos clientes
              <Badge variant="info" className="ml-1 text-[10px]">{customerGroups.length}</Badge>
            </TabsTrigger>
            <TabsTrigger value="vendorOf">
              <Truck className="size-3.5" aria-hidden="true" /> Proveedores
              <Badge variant="info" className="ml-1 text-[10px]">{vendorOf.length}</Badge>
            </TabsTrigger>
          </TabsList>

          {(['branches', 'warehouses', 'customerGroups', 'vendorOf'] as ScopeKey[]).map((key) => (
            <TabsContent key={key} value={key} className="space-y-2">
              <h3 className="text-sm font-medium">{SCOPE_LABELS[key]}</h3>
              <div
                className="max-h-72 overflow-y-auto rounded border border-border bg-bg/30"
                data-testid={`scope-list-${key}`}
              >
                {items[key].length === 0 ? (
                  <p className="px-3 py-4 text-center text-sm text-text-muted">
                    Sin elementos para asignar.
                  </p>
                ) : (
                  <ul className="divide-y divide-border">
                    {items[key].map((it) => {
                      const checked = selected[key].includes(it.id);
                      return (
                        <li key={it.id}>
                          <label className="flex cursor-pointer items-center gap-2 px-3 py-2 text-sm hover:bg-bg/60">
                            <input
                              type="checkbox"
                              checked={checked}
                              onChange={() => toggle(key, it.id)}
                              data-testid={`scope-${key}-${it.id}`}
                            />
                            <span className="flex-1">{it.label}</span>
                          </label>
                        </li>
                      );
                    })}
                  </ul>
                )}
              </div>
              <p className="text-xs text-text-muted">
                {selected[key].length} {SCOPE_LABELS[key].toLowerCase()} seleccionados.
              </p>
            </TabsContent>
          ))}
        </Tabs>
      </CardContent>
    </Card>
  );
}