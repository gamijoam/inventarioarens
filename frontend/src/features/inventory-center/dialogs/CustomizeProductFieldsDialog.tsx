/**
 * CustomizeProductFieldsDialog: modal para seleccionar visualmente qué campos
 * mostrar u ocultar en el formulario de creación de productos.
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
  PRODUCT_FORM_SECTIONS,
  type ProductFormVisibility,
} from '../productFormConfig';

export interface CustomizeProductFieldsDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  visibility: ProductFormVisibility;
  onChange: (newVisibility: ProductFormVisibility) => void;
  onReset: () => void;
}

export function CustomizeProductFieldsDialog({
  open,
  onOpenChange,
  visibility,
  onChange,
  onReset,
}: CustomizeProductFieldsDialogProps) {
  const visibleCount = useMemo(() => {
    return Object.values(visibility).filter(Boolean).length;
  }, [visibility]);

  const totalCount = useMemo(() => {
    return Object.keys(visibility).length;
  }, [visibility]);

  const handleToggle = (key: keyof ProductFormVisibility, checked: boolean) => {
    if (key === 'name') return; // El nombre siempre es obligatorio
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
                <DialogTitle>Personalizar campos del formulario</DialogTitle>
                <DialogDescription>
                  Elige qué casillas mostrar en la creación de productos. Los cambios se aplican al instante.
                </DialogDescription>
              </div>
            </div>
            <Badge variant="outline" className="text-xs font-normal">
              {visibleCount} de {totalCount} campos visibles
            </Badge>
          </div>
        </DialogHeader>

        <div className="space-y-4 py-2">
          {PRODUCT_FORM_SECTIONS.map((section) => (
            <div
              key={section.id}
              className="rounded-lg border border-border bg-surface/50 p-3 shadow-xs"
            >
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wider text-text-muted">
                {section.title}
              </h4>
              <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2">
                {section.fields.map((field) => {
                  const isChecked = field.required ? true : Boolean(visibility[field.key]);
                  return (
                    <label
                      key={field.key}
                      className="flex cursor-pointer items-start gap-2.5 rounded-md p-1.5 transition-colors hover:bg-bg/60"
                    >
                      <Checkbox
                        checked={isChecked}
                        disabled={field.required}
                        onCheckedChange={(checked) => handleToggle(field.key, Boolean(checked))}
                        className="mt-0.5"
                        data-testid={`checkbox-field-${field.key}`}
                      />
                      <div className="space-y-0.5 leading-none">
                        <span className="text-sm font-medium text-text-primary">
                          {field.label}
                        </span>
                        {field.required && (
                          <Badge variant="outline" className="ml-1.5 text-[10px] text-danger border-danger/30">
                            Obligatorio
                          </Badge>
                        )}
                        {field.description && !field.required && (
                          <p className="text-xs text-text-muted">{field.description}</p>
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
            data-testid="reset-fields-defaults-btn"
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
