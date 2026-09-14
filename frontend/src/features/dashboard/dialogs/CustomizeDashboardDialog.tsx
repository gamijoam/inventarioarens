/**
 * CustomizeDashboardDialog: modal para seleccionar visualmente qué métricas
 * e indicadores mostrar u ocultar en el Dashboard principal.
 */
import { useMemo } from 'react';
import { RotateCcw, SlidersHorizontal } from 'lucide-react';

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
import {
  DASHBOARD_SECTIONS,
  type DashboardVisibility,
} from '../dashboardConfig';

export interface CustomizeDashboardDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  visibility: DashboardVisibility;
  onChange: (newVisibility: DashboardVisibility) => void;
  onReset: () => void;
}

export function CustomizeDashboardDialog({
  open,
  onOpenChange,
  visibility,
  onChange,
  onReset,
}: CustomizeDashboardDialogProps) {
  const visibleCount = useMemo(() => {
    return Object.values(visibility).filter(Boolean).length;
  }, [visibility]);

  const totalCount = useMemo(() => {
    return Object.keys(visibility).length;
  }, [visibility]);

  const handleToggle = (key: keyof DashboardVisibility, checked: boolean) => {
    onChange({
      ...visibility,
      [key]: checked,
    });
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
        <DialogHeader>
          <div className="flex items-center justify-between gap-2 pr-6">
            <div className="flex items-center gap-2">
              <div className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <SlidersHorizontal className="size-4" />
              </div>
              <div>
                <DialogTitle>Personalizar Dashboard</DialogTitle>
                <DialogDescription>
                  Elige qué métricas y paneles mostrar en tu pantalla principal. Los cambios se aplican al instante.
                </DialogDescription>
              </div>
            </div>
            <Badge variant="outline" className="text-xs font-normal">
              {visibleCount} de {totalCount} elementos visibles
            </Badge>
          </div>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {DASHBOARD_SECTIONS.map((section) => (
            <div
              key={section.id}
              className="rounded-lg border border-border bg-surface/50 p-3 shadow-xs"
            >
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
                {section.title}
              </h4>
              <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                {section.items.map((item) => {
                  const isChecked = Boolean(visibility[item.key]);
                  return (
                    <label
                      key={item.key}
                      className="flex cursor-pointer items-start gap-2.5 rounded-md p-1.5 transition-colors hover:bg-bg/60"
                    >
                      <Checkbox
                        checked={isChecked}
                        onCheckedChange={(checked) => handleToggle(item.key, Boolean(checked))}
                        className="mt-0.5"
                        data-testid={`checkbox-dashboard-${item.key}`}
                      />
                      <div className="space-y-0.5 leading-none">
                        <span className="text-sm font-medium text-text-primary">
                          {item.label}
                        </span>
                        {item.description && (
                          <p className="text-xs text-text-muted">{item.description}</p>
                        )}
                      </div>
                    </label>
                  );
                })}
              </div>
            </div>
          ))}
        </div>

        <DialogFooter className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-between">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={onReset}
            className="gap-1.5 text-xs text-text-muted hover:text-text-primary"
            data-testid="reset-dashboard-defaults-btn"
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
