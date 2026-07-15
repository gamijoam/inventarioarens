/**
 * PermissionPicker: dialog para elegir un permiso del catalogo y asignarle
 * un efecto (allow/deny). Usado por UserOverridesTab.
 *
 * Filtra los permisos que ya estan en `existingPermissions` (Set con
 * key "permission:effect") para evitar duplicados.
 */
import { useMemo, useState } from 'react';
import { Search } from 'lucide-react';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Input } from '@/components/ui/Input';
import { Skeleton } from '@/components/ui/Skeleton';

import { usePermissionCatalog } from '@/features/access/api';

interface PermissionPickerProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onPick: (permission: string, effect: 'allow' | 'deny') => void;
  existingPermissions: Set<string>;
}

export function PermissionPicker({ open, onOpenChange, onPick, existingPermissions }: PermissionPickerProps) {
  const [search, setSearch] = useState('');
  const [effect, setEffect] = useState<'allow' | 'deny'>('allow');
  const { data, isLoading } = usePermissionCatalog();

  const allPermissions = useMemo(() => {
    if (!data) return [];
    return data.modules.flatMap((m) => m.actions.map((a) => a.permission));
  }, [data]);

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return allPermissions;
    return allPermissions.filter((p) => p.toLowerCase().includes(term));
  }, [allPermissions, search]);

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl">
        <DialogHeader>
          <DialogTitle>Agregar override</DialogTitle>
          <DialogDescription>
            Elegi el permiso y el efecto (allow = agregar, deny = quitar). El
            override se aplicara sobre los permisos del rol.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3">
          <div className="flex flex-col gap-2 sm:flex-row">
            <div className="relative flex-1">
              <Search
                className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-text-muted"
                aria-hidden="true"
              />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Buscar permiso..."
                className="pl-8"
                data-testid="permission-picker-search"
              />
            </div>
            <div className="flex gap-1">
              <Button
                size="sm"
                variant={effect === 'allow' ? 'primary' : 'outline'}
                onClick={() => setEffect('allow')}
                data-testid="permission-picker-effect-allow"
              >
                Allow
              </Button>
              <Button
                size="sm"
                variant={effect === 'deny' ? 'danger' : 'outline'}
                onClick={() => setEffect('deny')}
                data-testid="permission-picker-effect-deny"
              >
                Deny
              </Button>
            </div>
          </div>

          {isLoading ? (
            <Skeleton className="h-48 w-full" />
          ) : (
            <div
              className="max-h-72 overflow-y-auto rounded border border-border bg-bg/30"
              data-testid="permission-picker-list"
            >
              {filtered.length === 0 ? (
                <p className="px-3 py-4 text-center text-sm text-text-muted">Sin resultados.</p>
              ) : (
                <ul className="divide-y divide-border">
                  {filtered.map((p) => {
                    const alreadySet = existingPermissions.has(`${p}:allow`) || existingPermissions.has(`${p}:deny`);
                    return (
                      <li key={p}>
                        <button
                          type="button"
                          onClick={() => onPick(p, effect)}
                          disabled={alreadySet}
                          className="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-bg/60 disabled:opacity-50"
                          data-testid={`permission-picker-item-${p}`}
                        >
                          <Badge
                            variant={effect === 'allow' ? 'success' : 'warning'}
                            className="text-[10px]"
                          >
                            {effect}
                          </Badge>
                          <code className="flex-1 font-mono text-xs">{p}</code>
                          {alreadySet && (
                            <span className="text-xs text-text-muted">ya configurado</span>
                          )}
                        </button>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          )}
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Cancelar
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}