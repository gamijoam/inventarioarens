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
import { useProductForm } from '../forms';
import { ProductForm } from '../components/ProductForm';
import { useTags } from '../api';
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
  onSuccess?: () => void;
}

export function CreateProductDialog({ open, onOpenChange, onSuccess }: CreateProductDialogProps) {
  const navigate = useNavigate();
  const tenant = useSessionStore((state) => state.tenant);
  const { data: tags = [] } = useTags();

  const [visibility, setVisibility] = useState<ProductFormVisibility>(() =>
    getStoredProductFormVisibility(tenant?.id),
  );
  const [customizeOpen, setCustomizeOpen] = useState(false);

  useEffect(() => {
    setVisibility(getStoredProductFormVisibility(tenant?.id));
  }, [tenant?.id]);

  const handleUpdateVisibility = (newVisibility: ProductFormVisibility) => {
    setVisibility(newVisibility);
    saveStoredProductFormVisibility(newVisibility, tenant?.id);
  };

  const handleResetVisibility = () => {
    const def = resetStoredProductFormVisibility(tenant?.id);
    setVisibility(def);
  };

  const { form, onSubmit, isSubmitting } = useProductForm({
    mode: 'create',
    onSuccess: (data) => {
      // Cierra el dialog, navega al detalle del nuevo producto.
      onOpenChange(false);
      if (onSuccess) {
        onSuccess();
      } else if (data && typeof data === 'object' && 'id' in data) {
        void navigate({
          to: '/inventory/$productId',
          params: { productId: String((data as { id: number }).id) },
        });
      }
    },
  });

  const tagOptions = tags.map((t) => ({ value: t.id, label: t.name, color: t.color ?? undefined }));

  return (
    <>
      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
          <DialogHeader>
            <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between pr-6">
              <div>
                <DialogTitle>Nuevo producto</DialogTitle>
                <DialogDescription>
                  Completa los datos del producto. Los campos marcados con * son obligatorios.
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