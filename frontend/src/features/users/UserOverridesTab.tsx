/**
 * UserOverridesTab: gestion de overrides por usuario (allow/deny).
 *
 * Backend: PATCH /api/tenants/{tenant}/users/{user}/overrides
 *   Body: { items: [{ permission, effect: 'allow'|'deny' }] }
 *
 * Patron: lista de overrides actuales + boton "Agregar override" que
 * abre un PermissionPicker, y "Quitar" en cada fila. El save reemplaza
 * TODA la lista (semantica del backend).
 */
import { useEffect, useMemo, useState } from 'react';
import { Plus, Save, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Skeleton } from '@/components/ui/Skeleton';
import { PermissionPicker } from './PermissionPicker';
import {
  useReplaceUserOverrides,
  useUserOverrides,
} from './api';

interface UserOverridesTabProps {
  userId: number;
}

export function UserOverridesTab({ userId }: UserOverridesTabProps) {
  const [draft, setDraft] = useState<{ permission: string; effect: 'allow' | 'deny' }[]>([]);
  const [pickerOpen, setPickerOpen] = useState(false);
  const [saving, setSaving] = useState(false);

  const { data, isLoading, isError } = useUserOverrides(userId);
  const replace = useReplaceUserOverrides();

  // Cuando llegan los overrides del backend, sincronizar el draft local.
  useEffect(() => {
    if (data?.items) {
      setDraft(data.items.map((i) => ({ permission: i.permission, effect: i.effect })));
    }
  }, [data]);

  const draftSet = useMemo(
    () => new Set(draft.map((d) => `${d.permission}:${d.effect}`)),
    [draft],
  );

  function addOverride(permission: string, effect: 'allow' | 'deny') {
    setDraft((prev) => {
      // Quitar cualquier override previo de la misma permission.
      const filtered = prev.filter((p) => p.permission !== permission);
      return [...filtered, { permission, effect }];
    });
  }

  function removeLocal(permission: string) {
    setDraft((prev) => prev.filter((p) => p.permission !== permission));
  }

  function reset() {
    if (data?.items) {
      setDraft(data.items.map((i) => ({ permission: i.permission, effect: i.effect })));
    }
  }

  const isDirty = data && JSON.stringify(draft) !== JSON.stringify(
    (data?.items ?? []).map((i) => ({ permission: i.permission, effect: i.effect })),
  );

  async function save() {
    setSaving(true);
    try {
      await replace.mutateAsync({ userId, values: { items: draft } });
      toast.success(`Overrides guardados: ${draft.length}.`);
    } catch (err) {
      const msg = err instanceof Error ? err.message : 'Error al guardar overrides.';
      toast.error(msg);
    } finally {
      setSaving(false);
    }
  }

  void (async function handleRemovePlaceholder() {
    // Placeholder para una mejora futura: boton "Quitar" directo del servidor
    // (no del draft) para overrides que el usuario no quiere editar.
  })();

  return (
    <Card>
      <CardHeader className="flex flex-row items-start justify-between gap-2">
        <div>
          <CardTitle>Overrides por usuario</CardTitle>
          <CardDescription>
            Permisos extra (allow) o quitados (deny) sobre los del rol. Reemplazan
            el calculo base solo para este usuario.
          </CardDescription>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" size="sm" onClick={() => setPickerOpen(true)}>
            <Plus className="size-4" /> Agregar override
          </Button>
          <Button size="sm" onClick={save} loading={saving} disabled={!isDirty}>
            <Save className="size-4" /> Guardar
          </Button>
        </div>
      </CardHeader>
      <CardContent>
        {isLoading && <Skeleton className="h-32 w-full" />}
        {isError && (
          <p className="text-sm text-danger">No se pudo cargar los overrides.</p>
        )}
        {data && (
          <div className="space-y-1">
            {draft.length === 0 ? (
              <p className="rounded border border-dashed border-border bg-bg/30 px-3 py-4 text-center text-sm text-text-muted">
                Sin overrides. Los permisos efectivos son exactamente los del rol.
              </p>
            ) : (
              <ul className="divide-y divide-border rounded border border-border bg-bg/30">
                {draft.map((d) => {
                  const original = data.items.find((i) => i.permission === d.permission);
                  const isServer = !!original && original.effect === d.effect;
                  return (
                    <li
                      key={`${d.permission}:${d.effect}`}
                      className="flex items-center gap-2 px-3 py-2"
                    >
                      <Badge
                        variant={d.effect === 'allow' ? 'success' : 'warning'}
                        className="text-[10px]"
                      >
                        {d.effect === 'allow' ? 'allow' : 'deny'}
                      </Badge>
                      <code className="flex-1 font-mono text-xs">{d.permission}</code>
                      {!isServer && <Badge variant="info" className="text-[10px]">nuevo</Badge>}
                      <Button
                        size="icon-sm"
                        variant="ghost"
                        onClick={() => removeLocal(d.permission)}
                        title="Quitar"
                        aria-label={`Quitar override de ${d.permission}`}
                        data-testid={`remove-override-${d.permission}`}
                      >
                        <Trash2 className="size-4 text-danger" aria-hidden="true" />
                      </Button>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
        )}
        {isDirty && (
          <div className="mt-3 flex items-center gap-2 rounded border border-warning/40 bg-warning/5 px-3 py-2 text-sm text-text-secondary">
            <span>Cambios sin guardar.</span>
            <Button size="sm" variant="ghost" onClick={reset}>
              Descartar
            </Button>
          </div>
        )}
      </CardContent>

      <PermissionPicker
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        onPick={(permission, effect) => {
          addOverride(permission, effect);
          setPickerOpen(false);
        }}
        existingPermissions={draftSet}
      />
    </Card>
  );
}