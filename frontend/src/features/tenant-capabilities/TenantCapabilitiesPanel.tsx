import { useEffect, useState } from 'react';
import { LockKeyhole, PackageCheck, Save, SlidersHorizontal } from 'lucide-react';
import { toast } from 'sonner';

import { Button } from '@/components/ui/Button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/Card';
import { Spinner } from '@/components/ui/Spinner';
import { Switch } from '@/components/ui/Switch';
import { useSessionStore } from '@/stores/session';

import { useTenantCapabilities, useUpdateTenantCapabilities } from './api';

export function TenantCapabilitiesPanel() {
  const { data, isLoading, isError } = useTenantCapabilities();
  const update = useUpdateTenantCapabilities();
  const [selected, setSelected] = useState<string[]>([]);

  useEffect(() => {
    if (data) setSelected(data.enabled);
  }, [data]);

  if (isLoading) return <Spinner label="Cargando capacidades de la empresa..." />;

  if (isError || !data) {
    return (
      <div className="border-danger bg-danger/5 text-text-secondary rounded-lg border border-dashed p-4 text-sm">
        No se pudieron cargar las capacidades de esta empresa.
      </div>
    );
  }

  const required = data.capabilities.filter((capability) => capability.required);
  const optional = data.capabilities.filter((capability) => !capability.required);
  const optionalSelected = selected.filter((key) => optional.some((item) => item.key === key));
  const hasChanges =
    optionalSelected.length !==
      data.enabled.filter((key) => optional.some((item) => item.key === key)).length ||
    optionalSelected.some((key) => !data.enabled.includes(key));

  function toggle(capabilityKey: string, enabled: boolean): void {
    setSelected((current) => {
      if (enabled) return current.includes(capabilityKey) ? current : [...current, capabilityKey];
      return current.filter((key) => key !== capabilityKey);
    });
  }

  async function save(): Promise<void> {
    try {
      const result = await update.mutateAsync(optionalSelected);
      setSelected(result.enabled);
      useSessionStore.getState().setCapabilities(result.enabled);
      toast.success('Capacidades guardadas.');
    } catch (error) {
      toast.error(
        error instanceof Error ? error.message : 'No se pudieron guardar las capacidades.',
      );
    }
  }

  return (
    <div className="space-y-4">
      <Card className="overflow-hidden">
        <CardHeader className="border-border bg-primary/5 border-b p-5">
          <div className="flex items-start gap-3">
            <div className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-lg">
              <SlidersHorizontal className="size-5" aria-hidden="true" />
            </div>
            <div>
              <CardTitle>Capacidades activas</CardTitle>
              <CardDescription className="mt-1">
                Define que modulos puede utilizar esta empresa. Los permisos del usuario siguen
                controlando las acciones dentro de cada modulo.
              </CardDescription>
            </div>
          </div>
          <div className="text-text-secondary mt-4 flex flex-wrap gap-2 text-xs">
            <span className="bg-surface rounded-full px-2.5 py-1 shadow-sm">
              {selected.length} de {data.capabilities.length} activas
            </span>
            <span className="bg-surface rounded-full px-2.5 py-1 shadow-sm">
              {optional.length} modulos configurables
            </span>
          </div>
        </CardHeader>
      </Card>

      <CapabilityGroup
        title="Base de la plataforma"
        description="Estas capacidades forman el nucleo operativo y no se pueden desactivar."
        capabilities={required}
        selected={selected}
        onToggle={toggle}
        required
      />

      <CapabilityGroup
        title="Modulos opcionales"
        description="Activa solo los procesos que la empresa necesita. El backend tambien valida esta configuracion."
        capabilities={optional}
        selected={selected}
        onToggle={toggle}
      />

      <div className="flex justify-end">
        <Button
          leftIcon={<Save />}
          loading={update.isPending}
          disabled={!hasChanges}
          onClick={save}
          data-testid="capabilities-save"
        >
          Guardar capacidades
        </Button>
      </div>
    </div>
  );
}

function CapabilityGroup({
  title,
  description,
  capabilities,
  selected,
  onToggle,
  required = false,
}: {
  title: string;
  description: string;
  capabilities: {
    key: string;
    label: string;
    description: string;
    required: boolean;
  }[];
  selected: string[];
  onToggle: (key: string, enabled: boolean) => void;
  required?: boolean;
}) {
  return (
    <Card>
      <CardHeader>
        <div className="flex items-center gap-2">
          {required ? (
            <PackageCheck className="text-primary size-4" aria-hidden="true" />
          ) : (
            <SlidersHorizontal className="text-text-muted size-4" aria-hidden="true" />
          )}
          <CardTitle>{title}</CardTitle>
        </div>
        <CardDescription>{description}</CardDescription>
      </CardHeader>
      <CardContent className="grid gap-2 sm:grid-cols-2">
        {capabilities.map((capability) => {
          const enabled = selected.includes(capability.key);

          return (
            <div
              key={capability.key}
              className="border-border bg-bg/30 flex items-center justify-between gap-3 rounded-lg border p-3"
            >
              <div className="min-w-0">
                <div className="text-text-primary flex items-center gap-1.5 text-sm font-medium">
                  <span className="truncate">{capability.label}</span>
                  {required && (
                    <LockKeyhole className="text-text-muted size-3.5" aria-hidden="true" />
                  )}
                </div>
                <p className="text-text-muted mt-1 text-xs">{capability.description}</p>
              </div>
              <Switch
                aria-label={capability.label}
                checked={enabled}
                disabled={required}
                onCheckedChange={(checked) => onToggle(capability.key, checked)}
              />
            </div>
          );
        })}
      </CardContent>
    </Card>
  );
}
