/**
 * ProductImage.tsx — Componente variant-aware para mostrar imagenes de
 * productos. Acepta cualquier fuente:
 *  - URL absoluta (https://..., http://...)  → se usa directo
 *  - URL relativa (/storage/products/...)    → se usa directo
 *  - URL de proxy local (/api/images/{uuid})    → se sirve via backend
 *  - Sin src/alt                                → placeholder generado
 *
 * Usado en:
 *  - ProductCard / lista de productos (variant="thumb")
 *  - POS product search (variant="thumb")
 *  - ProductForm gallery (variant="medium")
 *  - Modal "vista previa" (variant="original")
 *
 * El componente NO decide la URL: la recibe del backend (ProductImage.url
 * o thumb_url o medium_url). Si la imagen es local, thumb_url etc. ya
 * apuntan al proxy /api/images/{uuid}.
 */
import { useState } from 'react';
import { cn } from '@/lib/cn';
import type { ProductImage } from '../schemas';

export type ProductImageVariantName = 'original' | 'medium' | 'thumb';

interface ProductImageProps {
  /**
   * ProductImage del backend. Si se pasa, se usan los URLs de cada variant.
   * Alternativamente se puede pasar `src` directo para casos puntuales.
   */
  image?: ProductImage;

  /** URL cruda (cuando NO viene del backend, ej: <img> para logos). */
  src?: string;

  /** Texto alternativo (obligatorio por a11y). */
  alt: string;

  /** Variante a usar si hay `image`. Default: 'thumb'. */
  variant?: ProductImageVariantName;

  /** Tamaño forzado en CSS. Si no se pasa, fill al contenedor. */
  className?: string;

  /** Object-fit (default: cover, util para crop cuadrado). */
  fit?: 'cover' | 'contain';

  /** Lazy loading (default true). */
  lazy?: boolean;

  /** Placeholder mientras carga o si falla. */
  fallback?: React.ReactNode;
}

/**
 * Resuelve el src segun el variant pedido, o devuelve el src crudo.
 * Si no hay image ni src, devuelve null (el componente muestra fallback).
 */
function resolveSrc(
  image: ProductImage | undefined,
  src: string | undefined,
  variant: ProductImageVariantName,
): string | null {
  if (src) return src;
  if (!image) return null;
  switch (variant) {
    case 'original':
      return image.url;
    case 'medium':
      return image.medium_url;
    case 'thumb':
      return image.thumb_url;
    default:
      return image.thumb_url;
  }
}

/**
 * Placeholder SVG inline (no hace request). Tamano 1x1 viewBox para
 * poder usar con cualquier width/height.
 */
function Placeholder({ className }: { className?: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="currentColor"
      className={cn('text-text-muted', className)}
      aria-hidden="true"
    >
      <path d="M4 5a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2H4Zm0 2h16v10H4V7Zm2 2v6h4v-4l2 2 2-2v4h4V9h-4v2l-2-2-2 2V9H6Z" />
    </svg>
  );
}

export function ProductImage({
  image,
  src,
  alt,
  variant = 'thumb',
  className,
  fit = 'cover',
  lazy = true,
  fallback,
}: ProductImageProps) {
  const resolved = resolveSrc(image, src, variant);
  const [error, setError] = useState(false);
  const [loaded, setLoaded] = useState(false);

  if (!resolved || error) {
    return (
      <div
        className={cn(
          'flex items-center justify-center bg-bg text-text-muted',
          className,
        )}
        role="img"
        aria-label={alt}
      >
        {fallback ?? <Placeholder className="size-1/2" />}
      </div>
    );
  }

  return (
    <div className={cn('relative overflow-hidden bg-bg', className)}>
      {!loaded && (
        <div className="absolute inset-0 flex items-center justify-center bg-bg">
          <Placeholder className="size-1/3 animate-pulse" />
        </div>
      )}
      <img
        src={resolved}
        alt={alt}
        loading={lazy ? 'lazy' : 'eager'}
        decoding="async"
        className={cn(
          'size-full',
          fit === 'cover' ? 'object-cover' : 'object-contain',
          loaded ? 'opacity-100' : 'opacity-0',
          'transition-opacity duration-150',
        )}
        onLoad={() => setLoaded(true)}
        onError={() => setError(true)}
      />
    </div>
  );
}
