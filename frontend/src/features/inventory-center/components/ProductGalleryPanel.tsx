/**
 * ProductGalleryPanel.tsx — Galeria de imagenes de solo lectura para el
 * detalle del producto (pestana General). Muestra la imagen principal grande
 * con thumbs debajo y una preview en modal al hacer click.
 *
 * Es distinto de ImageGallery (que incluye edicion: upload/reorder/primary/
 * delete). Este panel es puramente visual y se usa donde no hay permiso de
 * edicion o donde el contexto es de consulta (detalle, POS future).
 */
import { useState } from 'react';
import { X } from 'lucide-react';

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/Card';
import { cn } from '@/lib/cn';

import type { ProductImage } from '../schemas';
import { ProductImage as ProductImageView } from './ProductImage';

interface ProductGalleryPanelProps {
  images: ProductImage[];
}

export function ProductGalleryPanel({ images }: ProductGalleryPanelProps) {
  const [selectedId, setSelectedId] = useState<number | null>(null);

  const primary = images.find((image) => image.is_primary) ?? images[0];
  // El modal solo se abre cuando el usuario hace click en una imagen. Al
  // montar, selectedId es null y NO debe haber preview (bug: antes caia a
  // primary y el modal se abria solo al entrar al detalle).
  const selected = selectedId !== null
    ? images.find((image) => image.id === selectedId) ?? null
    : null;

  if (images.length === 0) {
    return null;
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle>Imágenes</CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {/* Imagen principal grande */}
        <button
          type="button"
          onClick={() => setSelectedId(primary?.id ?? null)}
          className="group relative block aspect-video w-full overflow-hidden rounded-lg border border-border bg-bg"
          aria-label="Ver imagen en grande"
        >
          <ProductImageView
            image={primary}
            variant="medium"
            alt={primary?.alt ?? primary?.original_name ?? 'Imagen principal del producto'}
            fit="contain"
            lazy={false}
            className="size-full"
          />
        </button>

        {/* Thumbs */}
        {images.length > 1 && (
          <div className="flex flex-wrap gap-2">
            {images.map((image) => (
              <button
                key={image.id}
                type="button"
                onClick={() => setSelectedId(image.id)}
                className={cn(
                  'relative size-16 overflow-hidden rounded-md border bg-bg transition',
                  selected?.id === image.id
                    ? 'border-primary ring-2 ring-primary/30'
                    : 'border-border hover:border-primary/50',
                )}
                aria-label={`Ver ${image.alt ?? image.original_name ?? 'imagen'}`}
              >
                <ProductImageView
                  image={image}
                  variant="thumb"
                  alt={image.alt ?? image.original_name ?? 'Miniatura'}
                  fit="cover"
                  className="size-full"
                />
                {image.is_primary && (
                  <span className="absolute left-1 top-1 rounded-full bg-warning/90 px-1.5 py-0.5 text-[9px] font-semibold uppercase text-warning-foreground">
                    Principal
                  </span>
                )}
              </button>
            ))}
          </div>
        )}

        {/* Preview en modal */}
        {selected && (
          <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4"
            role="dialog"
            aria-modal="true"
            aria-label="Vista previa de imagen"
            onClick={() => setSelectedId(null)}
          >
            <div className="relative max-h-[90vh] max-w-4xl" onClick={(e) => e.stopPropagation()}>
              <button
                type="button"
                onClick={() => setSelectedId(null)}
                className="absolute -right-3 -top-3 z-10 rounded-full bg-white p-1.5 text-black shadow"
                aria-label="Cerrar vista previa"
              >
                <X className="size-4" />
              </button>
              <ProductImageView
                image={selected}
                variant="original"
                alt={selected.alt ?? selected.original_name ?? 'Imagen ampliada'}
                fit="contain"
                lazy={false}
                className="max-h-[90vh] max-w-full rounded-lg"
              />
            </div>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
