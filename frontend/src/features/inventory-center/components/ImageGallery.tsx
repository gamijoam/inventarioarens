/**
 * ImageGallery.tsx — Grid de imagenes con drag-drop reorder, set-primary,
 * delete individual y dropzone para agregar nuevas. Es el "control completo"
 * que va dentro de ProductForm.
 *
 * Empty state: muestra el ImagePicker centrado para arrastrar la primera.
 * Una vez que hay imagenes, el picker se mueve arriba como "Agregar otra".
 *
 * Drag-drop reorder:
 *  - draggable=true sobre cada card
 *  - onDragStart guarda el id de la imagen arrastrada
 *  - onDragOver marca el drop target
 *  - onDrop calcula el nuevo orden y llama useReorderProductImages
 *  - Optimistic update: actualiza cache local antes de que responda el backend
 *
 * Set primary: estrella en la esquina. Click la marca como principal.
 *
 * Delete: X en la esquina. Pide confirmacion antes de soft-delete.
 */
import { useState } from 'react';
import { GripVertical, Star, Trash2 } from 'lucide-react';
import { toast } from 'sonner';

import { ConfirmDialog } from '@/components/ui/ConfirmDialog';
import { Spinner } from '@/components/ui/Spinner';
import { cn } from '@/lib/cn';

import {
  useDeleteProductImage,
  useReorderProductImages,
  useUpdateProductImage,
  useUploadProductImage,
} from '../api';
import type { ProductImage } from '../schemas';
import { ImagePicker } from './ImagePicker';
import { ProductImage as ProductImageView } from './ProductImage';

interface ImageGalleryProps {
  productId: number;
  images: ProductImage[];
  canEdit?: boolean;
}

function errorMessage(err: unknown): string | null {
  if (err instanceof Error) return err.message;
  return null;
}

export function ImageGallery({ productId, images, canEdit = true }: ImageGalleryProps) {
  const [dragId, setDragId] = useState<number | null>(null);
  const [dragOverId, setDragOverId] = useState<number | null>(null);
  const [localOrder, setLocalOrder] = useState<number[] | null>(null);
  const [pendingDelete, setPendingDelete] = useState<ProductImage | null>(null);

  const upload = useUploadProductImage(productId);
  const updateImage = useUpdateProductImage(productId);
  const deleteImage = useDeleteProductImage(productId);
  const reorder = useReorderProductImages(productId);

  const ordered = localOrder
    ? localOrder
      .map((id) => images.find((i) => i.id === id))
      .filter((i): i is ProductImage => Boolean(i))
    : images;

  function handleUpload(file: File) {
    upload.mutate(
      { file, alt: '' },
      {
        onSuccess: () => toast.success('Imagen subida'),
        onError: (err) => toast.error(errorMessage(err) ?? 'Error al subir'),
      },
    );
  }

  function handleSetPrimary(image: ProductImage) {
    if (image.is_primary) return;
    updateImage.mutate(
      { id: image.id, is_primary: true },
      {
        onSuccess: () => toast.success('Imagen principal actualizada'),
        onError: (err) => toast.error(errorMessage(err) ?? 'Error'),
      },
    );
  }

  function handleDelete(image: ProductImage) {
    deleteImage.mutate(image.id, {
      onSuccess: () => toast.success('Imagen eliminada'),
      onError: (err) => toast.error(errorMessage(err) ?? 'Error al eliminar'),
    });
  }

  function handleDrop(targetId: number) {
    if (dragId === null || dragId === targetId) return;
    const currentOrder = ordered.map((i) => i.id);
    const fromIdx = currentOrder.indexOf(dragId);
    const toIdx = currentOrder.indexOf(targetId);
    if (fromIdx === -1 || toIdx === -1) return;
    const newOrder = [...currentOrder];
    newOrder.splice(fromIdx, 1);
    newOrder.splice(toIdx, 0, dragId);

    // Optimistic: actualiza cache local para que el reorder se vea instantaneo.
    setLocalOrder(newOrder);
    setDragId(null);
    setDragOverId(null);

    reorder.mutate(newOrder, {
      onError: (err) => {
        toast.error(errorMessage(err) ?? 'Error al reordenar');
        setLocalOrder(null); // rollback visual
      },
      onSettled: () => setLocalOrder(null),
    });
  }

  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        {ordered.map((image) => (
          <div
            key={image.id}
            draggable={canEdit}
            onDragStart={() => setDragId(image.id)}
            onDragOver={(e) => {
              e.preventDefault();
              setDragOverId(image.id);
            }}
            onDragLeave={() => setDragOverId(null)}
            onDrop={() => handleDrop(image.id)}
            className={cn(
              'group relative aspect-square overflow-hidden rounded border bg-bg',
              dragOverId === image.id && dragId !== image.id
                ? 'border-primary ring-2 ring-primary/30'
                : 'border-border',
              dragId === image.id && 'opacity-40',
            )}
            data-testid={`image-card-${image.id}`}
          >
            <ProductImageView
              image={image}
              variant="thumb"
              alt={image.alt ?? image.original_name ?? 'Imagen del producto'}
              fit="cover"
              lazy
              className="size-full"
            />

            {image.is_primary && (
              <span
                className="absolute left-2 top-2 rounded-full bg-warning/90 px-2 py-0.5 text-[10px] font-semibold uppercase text-warning-foreground"
                data-testid="primary-badge"
              >
                Principal
              </span>
            )}

            {canEdit && (
              <div className="absolute inset-x-0 bottom-0 flex items-center justify-between gap-1 bg-gradient-to-t from-black/70 to-transparent p-2 opacity-0 transition-opacity group-hover:opacity-100">
                <button
                  type="button"
                  draggable={false}
                  onClick={(e) => {
                    e.stopPropagation();
                    handleSetPrimary(image);
                  }}
                  disabled={image.is_primary || updateImage.isPending}
                  className="rounded bg-black/40 p-1 text-yellow-400 hover:bg-black/60 disabled:opacity-30"
                  aria-label={image.is_primary ? 'Ya es principal' : 'Marcar como principal'}
                  data-testid={`set-primary-${image.id}`}
                >
                  <Star className="size-4" fill={image.is_primary ? 'currentColor' : 'none'} />
                </button>

                <button
                  type="button"
                  draggable={false}
                  onClick={(e) => e.stopPropagation()}
                  className="cursor-grab rounded bg-black/40 p-1 text-white"
                  aria-label="Arrastrar para reordenar"
                  data-testid={`reorder-handle-${image.id}`}
                >
                  <GripVertical className="size-4" />
                </button>

                <ConfirmDialog
                  open={pendingDelete?.id === image.id}
                  onOpenChange={(open) => !open && setPendingDelete(null)}
                  title="Eliminar imagen"
                  description={`Eliminar "${image.original_name ?? image.uuid}"? La fila se soft-delete y el archivo se borra del storage despues de 30 dias.`}
                  confirmLabel="Eliminar"
                  variant="danger"
                  onConfirm={() => {
                    handleDelete(image);
                    setPendingDelete(null);
                  }}
                />
                <button
                  type="button"
                  onClick={(e) => {
                    e.stopPropagation();
                    setPendingDelete(image);
                  }}
                  className="rounded bg-black/40 p-1 text-red-400 hover:bg-black/60"
                  aria-label="Eliminar imagen"
                  data-testid={`delete-${image.id}`}
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            )}

            {updateImage.isPending && (
              <div className="absolute inset-0 flex items-center justify-center bg-black/40">
                <Spinner className="size-5 text-white" />
              </div>
            )}
          </div>
        ))}

        {/* Dropzone flotante para agregar imagenes, solo si NO hay imagenes todavia. */}
        {ordered.length === 0 && canEdit && (
          <div className="col-span-full">
            <ImagePicker onFileSelected={handleUpload} disabled={upload.isPending} />
            {upload.isPending && (
              <p className="mt-2 text-center text-xs text-text-muted">
                <Spinner className="mr-1 inline size-3" /> Subiendo imagen...
              </p>
            )}
          </div>
        )}
      </div>

      {/* Dropzone compacto cuando ya hay imagenes */}
      {ordered.length > 0 && canEdit && (
        <div className="border-t border-border pt-3">
          <ImagePicker
            onFileSelected={handleUpload}
            disabled={upload.isPending}
            hint="Agregar otra imagen"
            className={upload.isPending ? 'pointer-events-none opacity-60' : undefined}
          />
        </div>
      )}
    </div>
  );
}
