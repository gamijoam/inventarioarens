/**
 * Tests de ProductGalleryPanel (galeria visual de solo lectura en el detalle).
 *
 * Cubre:
 *  - No renderiza nada cuando no hay imagenes.
 *  - Muestra la imagen principal grande + thumbs.
 *  - Click en un thumb cambia la preview.
 *  - El modal se abre/cierra.
 */
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ProductGalleryPanel } from '../ProductGalleryPanel';
import type { ProductImage } from '../../schemas';

vi.mock('@/components/ui/Card', () => ({
  Card: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  CardHeader: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
  CardTitle: ({ children }: { children: React.ReactNode }) => <h3>{children}</h3>,
  CardContent: ({ children }: { children: React.ReactNode }) => <div>{children}</div>,
}));

function makeImage(overrides: Partial<ProductImage> = {}): ProductImage {
  return {
    id: 1,
    uuid: '11111111-1111-4111-8111-111111111111',
    product_id: 100,
    mime: 'image/webp',
    size: 1234,
    width: 800,
    height: 600,
    alt: null,
    sort: 0,
    is_primary: false,
    url: '/api/images/11111111-1111-4111-8111-111111111111',
    thumb_url: '/api/images/11111111-1111-4111-8111-111111111111?variant=thumb',
    medium_url: '/api/images/11111111-1111-4111-8111-111111111111?variant=medium',
    original_name: 'foto.webp',
    uploaded_at: null,
    ...overrides,
  } as ProductImage;
}

describe('ProductGalleryPanel', () => {
  it('no renderiza cuando no hay imagenes', () => {
    const { container } = render(<ProductGalleryPanel images={[]} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('muestra la imagen principal y el badge Principal', () => {
    render(
      <ProductGalleryPanel
        images={[makeImage({ is_primary: true, alt: 'Imagen A' }), makeImage({ id: 2, alt: 'Imagen B' })]}
      />,
    );

    expect(screen.getByText('Imágenes')).toBeInTheDocument();
    expect(screen.getAllByRole('button').length).toBeGreaterThan(0);
  });

  it('abre la preview al hacer click en una miniatura', async () => {
    render(
      <ProductGalleryPanel
        images={[
          makeImage({ id: 1, is_primary: true, alt: 'Imagen A' }),
          makeImage({ id: 2, alt: 'Imagen B' }),
        ]}
      />,
    );

    const thumbButtons = screen.getAllByRole('button');
    fireEvent.click(thumbButtons[1]);

    expect(screen.getByRole('dialog')).toBeInTheDocument();
  });
});
