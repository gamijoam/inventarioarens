/**
 * CreateProductDialog: dialog para crear un producto nuevo.
 * Usa useProductForm(mode='create') + ProductForm.
 *
 * Props:
 *  - open / onOpenChange: controlan la visibilidad.
 *  - onSuccess?: callback opcional tras crear (ej: navegar al detalle).
 */
import { useEffect, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Settings } from 'lucide-react';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { Button } from '@/components/ui/Button';
import { useSessionStore } from '@/stores/session';
import { useUiPreferences, useUpdateUiPreferences } from '@/features/company-settings/api';
import { useProductForm } from '../forms';
import { ProductForm } from '../components/ProductForm';
import { useTags } from '../api';
import type { Product } from '../schemas';
import {
  type ProductFormVisibility,
  getStoredProductFormVisibility,
  saveStoredProductFormVisibility,
  resetStoredProductFormVisibility,
} from '../productFormConfig';
import { CustomizeProductFieldsDialog } from './CustomizeProductFieldsDialog';

export interface CreateProductDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: (product?: Product) => void;
  /** Nombre precargado (ej: lo que el usuario escribio en el buscador de compras). */
  initialName?: string;
}

export function CreateProductDialog({ open, onOpenChange, onSuccess, initialName }: CreateProductDialogProps) {
  const navigate = useNavigate();
  const tenant = useSessionStore((state) => state.tenant);
  const { data: tags = [] } = useTags();
  const { data: uiPreferences } = useUiPreferences();
  const updateUiPreferences = useUpdateUiPreferences();

  const [visibility, setVisibility] = useState<ProductFormVisibility>(() =>
    getStoredProductFormVisibility(tenant?.id),
  );
  const [customizeOpen, setCustomizeOpen] = useState(false);

  useEffect(() => {
    if (uiPreferences?.product_form_visibility) {
      setVisibility({
        ...getStoredProductFormVisibility(tenant?.id),
        ...uiPreferences.product_form_visibility,
        name: true,
        base_price: true,
      });
    } else {
      setVisibility(getStoredProductFormVisibility(tenant?.id));
    }
  }, [tenant?.id, uiPreferences?.product_form_visibility]);

  const handleUpdateVisibility = (newVisibility: ProductFormVisibility) => {
    const safeVisibility = { ...newVisibility, name: true, base_price: true };
    setVisibility(safeVisibility);
    saveStoredProductFormVisibility(safeVisibility, tenant?.id);
    updateUiPreferences.mutate({
      product_form_visibility: safeVisibility as unknown as Record<string, boolean>,
    });
  };

  const handleResetVisibility = () => {
    const def = resetStoredProductFormVisibility(tenant?.id);
    setVisibility(def);
    updateUiPreferences.mutate({
      product_form_visibility: def as unknown as Record<string, boolean>,
    });
  };

  const { form, onSubmit, isSubmitting, resetForm } = useProductForm({
    mode: 'create',
    initialValues: initialName ? { name: initialName } : undefined,
    onSuccess: (data) => {
      // Cierra el dialog y entrega el producto creado (si el padre lo pidio).
      onOpenChange(false);
      const created = data && typeof data === 'object' && 'id' in data ? (data as Product) : undefined;
      if (onSuccess) {
        onSuccess(created);
      } else if (created) {
        void navigate({
          to: '/inventory/$productId',
          params: { productId: String(created.id) },
        });
      }
    },
  });

  // Al abrir, reinicia el formulario para no arrastrar los datos del producto
  // creado anteriormente (el componente no se desmonta al cerrarse).
  useEffect(() => {
    if (open) {
      resetForm(initialName ? { name: initialName } : undefined);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialName]);

  const tagOptions = tags.map((t) => ({ value: t.id, label: t.name, color: t.color ?? undefined }));

  return (
    <>
      <Dialog
        key={open ? `create-${initialName ?? ''}` : 'create-closed'}
        open={open}
        onOpenChange={onOpenChange}
      >
        <DialogContent className="h-[95vh] max-h-[95vh] w-[98vw] max-w-[98vw] overflow-hidden p-0 flex flex-col gap-0 rounded-xl border border-border bg-surface shadow-2xl">
          <DialogHeader className="px-6 py-3.5 border-b border-border bg-surface-subtle/40 shrink-0">
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pr-8">
              <div>
                <div className="flex items-center gap-2">
                  <span className="inline-flex items-center gap-1 rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-mono font-bold uppercase tracking-wider text-primary border border-primary/20">
                    ERP
                  </span>
                  <DialogTitle className="text-base font-bold tracking-tight">Nuevo producto</DialogTitle>
                </div>
                <DialogDescription className="text-xs text-text-muted mt-0.5">
                  Ficha maestra de artículo. Completa los datos requeridos (*) y navega con [F1 - F6].
                </DialogDescription>
              </div>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => setCustomizeOpen(true)}
                className="shrink-0 gap-1.5 text-xs text-text-muted hover:text-text-primary self-start sm:self-center"
                data-testid="customize-fields-btn"
              >
                <Settings className="size-3.5" />
                Personalizar campos
              </Button>
            </div>
          </DialogHeader>
          <div className="flex-1 min-h-0 flex flex-col overflow-hidden">
            <ProductForm
              form={form}
              tagOptions={tagOptions}
              onSubmit={onSubmit}
              isSubmitting={isSubmitting}
              onCancel={() => onOpenChange(false)}
              submitLabel="Crear producto"
              visibility={visibility}
              showAdvancedToggle={true}
            />
          </div>
        </DialogContent>
      </Dialog>

      <CustomizeProductFieldsDialog
        open={customizeOpen}
        onOpenChange={setCustomizeOpen}
        visibility={visibility}
        onChange={handleUpdateVisibility}
        onReset={handleResetVisibility}
      />
    </>
  );
}