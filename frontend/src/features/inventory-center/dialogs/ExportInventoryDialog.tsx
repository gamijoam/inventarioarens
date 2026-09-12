/**
 * ExportInventoryDialog: Modal para seleccionar las columnas a incluir al exportar
 * el inventario en formato CSV (/inventory).
 *
 * Ofrece presets rápidos ("Solo nombres", "Nombre y SKU", "Nombre y Código de barras",
 * "Nombre y Stock", "Estándar", "Todos") y selección individual por casillas.
 * Recuerda la preferencia del usuario por tenant en localStorage.
 */
import { useEffect, useMemo, useState } from 'react';
import { Download, FileSpreadsheet, Sparkles } from 'lucide-react';

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
import { useSessionStore } from '@/stores/session';
import type { InventoryFilters } from '../schemas';
import {
  INVENTORY_EXPORT_COLUMNS,
  INVENTORY_EXPORT_PRESETS,
  getStoredExportColumns,
  saveStoredExportColumns,
  type InventoryExportColumnKey,
} from '../inventoryExportConfig';

export interface ExportInventoryDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  filters: InventoryFilters;
  exportProducts: {
    exportCsv: (filters: InventoryFilters, columns?: string[]) => Promise<void>;
    isExporting: boolean;
  };
}

export function ExportInventoryDialog({
  open,
  onOpenChange,
  filters,
  exportProducts,
}: ExportInventoryDialogProps) {
  const tenantId = useSessionStore((s) => s.tenant?.id);

  const [selectedColumns, setSelectedColumns] = useState<InventoryExportColumnKey[]>(() =>
    getStoredExportColumns(tenantId),
  );

  // Sincronizar selección al abrir el modal
  useEffect(() => {
    if (open) {
      setSelectedColumns(getStoredExportColumns(tenantId));
    }
  }, [open, tenantId]);

  const selectedSet = useMemo(() => new Set(selectedColumns), [selectedColumns]);

  const handleToggle = (key: InventoryExportColumnKey) => {
    if (key === 'name') return; // Nombre es obligatorio en el reporte

    setSelectedColumns((prev) => {
      const exists = prev.includes(key);
      const next: InventoryExportColumnKey[] = exists ? prev.filter((k) => k !== key) : [...prev, key];
      return next.includes('name') ? next : ['name', ...next];
    });
  };

  const handleApplyPreset = (presetColumns: InventoryExportColumnKey[]) => {
    const next: InventoryExportColumnKey[] = presetColumns.includes('name')
      ? presetColumns
      : ['name', ...presetColumns];
    setSelectedColumns(next);
  };

  const handleExport = async () => {
    saveStoredExportColumns(selectedColumns, tenantId);
    await exportProducts.exportCsv(filters, selectedColumns);
    onOpenChange(false);
  };

  const basicColumns = INVENTORY_EXPORT_COLUMNS.filter((c) => c.category === 'basic');
  const stockColumns = INVENTORY_EXPORT_COLUMNS.filter((c) => c.category === 'stock');
  const pricingColumns = INVENTORY_EXPORT_COLUMNS.filter((c) => c.category === 'pricing');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto" data-testid="export-inventory-dialog">
        <DialogHeader>
          <div className="flex items-center justify-between gap-2 pr-6">
            <div className="flex items-center gap-2">
              <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <FileSpreadsheet className="size-4" />
              </div>
              <div>
                <DialogTitle>Exportar inventario a CSV</DialogTitle>
                <DialogDescription>
                  Selecciona qué columnas deseas incluir en la descarga. Se recordará tu elección.
                </DialogDescription>
              </div>
            </div>
            <Badge variant="outline" className="text-xs font-normal" data-testid="selected-columns-count">
              {selectedColumns.length} de {INVENTORY_EXPORT_COLUMNS.length} columnas
            </Badge>
          </div>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {/* Presets rápidos */}
          <div className="rounded-lg border border-border bg-surface/50 p-3 shadow-xs">
            <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-text-muted">
              <Sparkles className="size-3.5 text-primary" />
              <span>Plantillas de exportación rápida</span>
            </div>
            <div className="flex flex-wrap gap-2">
              {INVENTORY_EXPORT_PRESETS.map((preset) => {
                const isCurrent =
                  preset.columns.length === selectedColumns.length &&
                  preset.columns.every((k) => selectedSet.has(k));

                return (
                  <Button
                    key={preset.id}
                    type="button"
                    variant={isCurrent ? 'primary' : 'outline'}
                    size="sm"
                    className={cn(
                      'text-xs transition-all',
                      isCurrent && 'shadow-xs font-medium',
                    )}
                    onClick={() => handleApplyPreset(preset.columns)}
                    data-testid={`preset-export-${preset.id}`}
                  >
                    {preset.label}
                  </Button>
                );
              })}
            </div>
          </div>

          {/* Columnas agrupadas */}
          <div className="space-y-4">
            {/* Categoría: Información básica */}
            <div>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
                Información del Producto
              </h4>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {basicColumns.map((col) => {
                  const isChecked = selectedSet.has(col.key);
                  const isRequired = col.required === true;

                  return (
                    <label
                      key={col.key}
                      className={cn(
                        'flex cursor-pointer select-none items-start gap-3 rounded-lg border p-2.5 transition-colors',
                        isChecked
                          ? 'border-primary/40 bg-primary/5 text-text'
                          : 'border-border bg-surface/50 text-text-muted hover:border-border-focus',
                        isRequired && 'cursor-default opacity-90',
                      )}
                      data-testid={`export-column-${col.key}`}
                    >
                      <Checkbox
                        checked={isChecked}
                        disabled={isRequired}
                        onCheckedChange={() => handleToggle(col.key)}
                        className="mt-0.5"
                      />
                      <div className="flex-1 text-xs">
                        <div className="flex items-center gap-1.5 font-medium text-text">
                          <span>{col.label}</span>
                          {isRequired && (
                            <Badge variant="outline" className="px-1 py-0 text-[10px] leading-tight">
                              Obligatorio
                            </Badge>
                          )}
                        </div>
                        <p className="mt-0.5 text-[11px] text-text-muted">{col.description}</p>
                      </div>
                    </label>
                  );
                })}
              </div>
            </div>

            {/* Categoría: Stock y Almacén */}
            <div>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
                Stock y Disponibilidad
              </h4>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {stockColumns.map((col) => {
                  const isChecked = selectedSet.has(col.key);

                  return (
                    <label
                      key={col.key}
                      className={cn(
                        'flex cursor-pointer select-none items-start gap-3 rounded-lg border p-2.5 transition-colors',
                        isChecked
                          ? 'border-primary/40 bg-primary/5 text-text'
                          : 'border-border bg-surface/50 text-text-muted hover:border-border-focus',
                      )}
                      data-testid={`export-column-${col.key}`}
                    >
                      <Checkbox
                        checked={isChecked}
                        onCheckedChange={() => handleToggle(col.key)}
                        className="mt-0.5"
                      />
                      <div className="flex-1 text-xs">
                        <span className="font-medium text-text">{col.label}</span>
                        <p className="mt-0.5 text-[11px] text-text-muted">{col.description}</p>
                      </div>
                    </label>
                  );
                })}
              </div>
            </div>

            {/* Categoría: Precios y Costos */}
            <div>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
                Precios y Costos
              </h4>
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {pricingColumns.map((col) => {
                  const isChecked = selectedSet.has(col.key);

                  return (
                    <label
                      key={col.key}
                      className={cn(
                        'flex cursor-pointer select-none items-start gap-3 rounded-lg border p-2.5 transition-colors',
                        isChecked
                          ? 'border-primary/40 bg-primary/5 text-text'
                          : 'border-border bg-surface/50 text-text-muted hover:border-border-focus',
                      )}
                      data-testid={`export-column-${col.key}`}
                    >
                      <Checkbox
                        checked={isChecked}
                        onCheckedChange={() => handleToggle(col.key)}
                        className="mt-0.5"
                      />
                      <div className="flex-1 text-xs">
                        <span className="font-medium text-text">{col.label}</span>
                        <p className="mt-0.5 text-[11px] text-text-muted">{col.description}</p>
                      </div>
                    </label>
                  );
                })}
              </div>
            </div>
          </div>
        </div>

        <DialogFooter className="gap-2 sm:gap-0">
          <Button
            type="button"
            variant="ghost"
            onClick={() => onOpenChange(false)}
            disabled={exportProducts.isExporting}
          >
            Cancelar
          </Button>
          <Button
            type="button"
            variant="primary"
            leftIcon={<Download className="size-4" />}
            onClick={handleExport}
            loading={exportProducts.isExporting}
            data-testid="confirm-export-csv"
          >
            Descargar CSV ({selectedColumns.length})
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
