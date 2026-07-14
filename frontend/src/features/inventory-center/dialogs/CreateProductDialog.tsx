/**
 * CreateProductDialog: dialog para crear un producto nuevo.
 * Usa useProductForm(mode='create') + ProductForm.
 *
 * Props:
 *  - open / onOpenChange: controlan la visibilidad.
 *  - onSuccess?: callback opcional tras crear (ej: navegar al detalle).
 */
import { useNavigate } from '@tanstack/react-router';

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/Dialog';
import { useProductForm } from '../forms';
import { ProductForm } from '../components/ProductForm';
import { useTags } from '../api';

export interface CreateProductDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}

export function CreateProductDialog({ open, onOpenChange, onSuccess }: CreateProductDialogProps) {
  const navigate = useNavigate();
  const { data: tags = [] } = useTags();
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
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Nuevo producto</DialogTitle>
          <DialogDescription>
            Completa los datos del producto. Los campos marcados con * son obligatorios.
          </DialogDescription>
        </DialogHeader>
        <ProductForm
          form={form}
          tagOptions={tagOptions}
          onSubmit={onSubmit}
          isSubmitting={isSubmitting}
          onCancel={() => onOpenChange(false)}
          submitLabel="Crear producto"
        />
      </DialogContent>
    </Dialog>
  );
}