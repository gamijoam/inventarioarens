/**
 * CustomizeInventoryColumnsDialog: Modal para personalizar y alternar las columnas
 * visibles en la tabla del Centro de Inventario (/inventory).
 *
 * Incluye presets rápidos con un solo clic (ej. "Solo Producto y Stock", "Mostrador / Código de barras",
 * "Estándar", "Completo") y casillas para personalizar columna por columna.
 */
import { useMemo } from 'react';
import { Columns3, RotateCcw, Sparkles } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Checkbox } from '@/components/ui/Checkbox';
import { Button } from '@/components/ui/Button';
import { Badge } from '@/components/ui/Badge';
import { cn } from '@/lib/cn';
import {
  INVENTORY_COLUMN_DEFINITIONS,
  INVENTORY_COLUMN_PRESETS,
  type InventoryTableColumnsVisibility,
} from '../inventoryColumnsConfig';

export interface CustomizeInventoryColumnsDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  visibility: InventoryTableColumnsVisibility;
  onChange: (newVisibility: InventoryTableColumnsVisibility) => void;
  onReset: () => void;
}

export function CustomizeInventoryColumnsDialog({
  open,
  onOpenChange,
  visibility,
  onChange,
  onReset,
}: CustomizeInventoryColumnsDialogProps) {
  const visibleCount = useMemo(() => {
    return Object.values(visibility).filter(Boolean).length;
  }, [visibility]);

  const totalCount = useMemo(() => {
    return INVENTORY_COLUMN_DEFINITIONS.length;
  }, []);

  const handleToggle = (key: keyof InventoryTableColumnsVisibility, checked: boolean) => {
    if (key === 'name') return; // Nombre del producto siempre es obligatorio
    onChange({
      ...visibility,
      [key]: checked,
      name: true,
    });
  };

  const handleApplyPreset = (presetColumns: InventoryTableColumnsVisibility) => {
    onChange({
      ...presetColumns,
      name: true,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <div className="flex items-center justify-between gap-2 pr-6">
            <div className="flex items-center gap-2">
              <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <Columns3 className="size-4" />
              </div>
              <div>
                <DialogTitle>Personalizar columnas del inventario</DialogTitle>
                <DialogDescription>
                  Elige qué columnas mostrar en la tabla de productos. Los cambios son persistentes.
                </DialogDescription>
              </div>
            </div>
            <Badge variant="outline" className="text-xs font-normal">
              {visibleCount} de {totalCount} columnas visibles
            </Badge>
          </div>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {/* Presets rápidos */}
          <div className="rounded-lg border border-border bg-surface/50 p-3 shadow-xs">
            <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-text-muted">
              <Sparkles className="size-3.5 text-primary" />
              <span>Vistas rápidas preconfiguradas</span>
            </div>
            <div className="flex flex-wrap gap-2">
              {INVENTORY_COLUMN_PRESETS.map((preset) => {
                // Chequear si este preset coincide exactamente con la configuración actual
                const isCurrent = Object.keys(preset.columns).every((k) => {
                  const key = k as keyof InventoryTableColumnsVisibility;
                  return Boolean(visibility[key]) === Boolean(preset.columns[key]);
                });

                return (
                  <Button
                    key={preset.id}
                    type="button"
                    variant={isCurrent ? 'primary' : 'outline'}
                    size="sm"
                    className={cn(
                      'text-xs transition-all',
                      isCurrent && 'shadow-xs font-semibold',
                    )}
                    onClick={() => handleApplyPreset(preset.columns)}
                    data-testid={`preset-btn-${preset.id}`}
                    title={preset.description}
                  >
                    {preset.name}
                  </Button>
                );
              })}
            </div>
          </div>

          {/* Columnas individuales */}
          <div className="rounded-lg border border-border bg-surface/50 p-3 shadow-xs">
            <h4 className="mb-2.5 text-xs font-semibold uppercase tracking-wider text-text-muted">
              Columnas de la tabla
            </h4>
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
              {INVENTORY_COLUMN_DEFINITIONS.map((col) => {
                const isChecked = col.required ? true : Boolean(visibility[col.key]);
                return (
                  <label
                    key={col.key}
                    className="flex cursor-pointer items-start gap-2.5 rounded-md p-1.5 transition-colors hover:bg-bg/60"
                  >
                    <Checkbox
                      checked={isChecked}
                      disabled={col.required}
                      onCheckedChange={(checked) => handleToggle(col.key, Boolean(checked))}
                      className="mt-0.5"
                      data-testid={`checkbox-col-${col.key}`}
                    />
                    <div className="space-y-0.5 leading-none">
                      <div className="flex items-center gap-1.5">
                        <span className="text-sm font-medium text-text-primary">{col.label}</span>
                        {col.required && (
                          <Badge
                            variant="outline"
                            className="text-[10px] text-danger border-danger/30"
                          >
                            Obligatorio
                          </Badge>
                        )}
                      </div>
                      {col.description && (
                        <p className="text-xs text-text-muted">{col.description}</p>
                      )}
                    </div>
                  </label>
                );
              })}
            </div>
          </div>
        </div>

        <DialogFooter className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onReset}
            className="gap-1.5 text-xs text-text-muted hover:text-text-primary"
            data-testid="reset-columns-defaults-btn"
          >
            <RotateCcw className="size-3.5" />
            Restablecer predeterminados
          </Button>
          <Button type="button" onClick={() => onOpenChange(false)}>
            Listo
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
