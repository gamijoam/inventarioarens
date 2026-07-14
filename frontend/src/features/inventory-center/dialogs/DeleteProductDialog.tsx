/**
 * DeleteProductDialog: dialog de confirmacion para eliminar un producto.
 * Usa ConfirmDialog internamente y useDeleteProduct.
 */
import { useNavigate } from '@tanstack/react-router';

import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { useDeleteProduct } from '../api';

export interface DeleteProductDialogProps {
  productId: number;
  productName: string;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess?: () => void;
}

export function DeleteProductDialog({
  productId,
  productName,
  open,
  onOpenChange,
  onSuccess,
}: DeleteProductDialogProps) {
  const navigate = useNavigate();
  const deleteProduct = useDeleteProduct();

  const handleConfirm = async () => {
    await deleteProduct.mutateAsync(productId);
    onOpenChange(false);
    onSuccess?.();
    // Si no hay onSuccess custom, navega al listado.
    void navigate({
      to: '/inventory',
      search: { search: '', tracking: 'all', stock: 'all', status: 'all', page: 1 },
    });
  };

  return (
    <ConfirmDialog
      open={open}
      onOpenChange={onOpenChange}
      title="Eliminar producto"
      description={
        <>
          Estás a punto de eliminar <strong>{productName}</strong>. Esta acción es
          <strong className="text-danger"> irreversible</strong>: el producto se marcará como
          inactivo y no podrá venderse ni editarse.
        </>
      }
      confirmLabel="Eliminar"
      cancelLabel="Cancelar"
      variant="danger"
      loading={deleteProduct.isPending}
      onConfirm={handleConfirm}
    />
  );
}