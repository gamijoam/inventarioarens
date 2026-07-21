import { describe, expect, it } from 'vitest';
import { render, screen } from '@testing-library/react';

import { ProductImage } from './ProductImage';
import type { ProductImage as ProductImageT } from '../schemas';

const sampleImage: ProductImageT = {
  id: 1,
  uuid: '37e4b97e-1234-4abc-8def-1234567890ab',
  product_id: 1,
  mime: 'image/webp',
  size: 11358,
  width: 2048,
  height: 1365,
  alt: 'Nevera ejecutiva',
  sort: 0,
  is_primary: true,
  url: 'https://app.miinventariofacil.com/storage/products/1/nevera.webp',
  thumb_url: 'https://app.miinventariofacil.com/storage/products/1/nevera_thumb.webp',
  medium_url: 'https://app.miinventariofacil.com/storage/products/1/nevera_medium.webp',
  original_name: 'nevera.webp',
  uploaded_at: '2026-07-21T14:00:00Z',
};

describe('ProductImage', () => {
  it('renderiza <img> con la URL por variant', () => {
    const { rerender } = render(
      <ProductImage image={sampleImage} variant="thumb" alt={sampleImage.alt!} />,
    );
    const img = screen.getByRole('img', { name: sampleImage.alt! });
    expect(img).toHaveAttribute('src', sampleImage.thumb_url);
    expect(img).toHaveAttribute('alt', sampleImage.alt!);
    expect(img).toHaveAttribute('loading', 'lazy');

    rerender(
      <ProductImage image={sampleImage} variant="medium" alt="x" />,
    );
    expect(screen.getByRole('img', { name: 'x' })).toHaveAttribute('src', sampleImage.medium_url);

    rerender(
      <ProductImage image={sampleImage} variant="original" alt="x" />,
    );
    expect(screen.getByRole('img', { name: 'x' })).toHaveAttribute('src', sampleImage.url);
  });

  it('acepta src crudo (sin image prop)', () => {
    const customUrl = 'https://example.com/foo.jpg';
    render(
      <ProductImage src={customUrl} alt="Custom" variant="thumb" />,
    );
    expect(screen.getByRole('img', { name: 'Custom' })).toHaveAttribute('src', customUrl);
  });

  it('muestra placeholder cuando no hay image ni src', () => {
    const { container } = render(
      <ProductImage alt="Sin imagen" variant="thumb" />,
    );
    // aria-label = alt, role = img, no <img> real.
    expect(screen.queryByRole('img', { name: 'Sin imagen' })).toBeInTheDocument();
    expect(container.querySelector('img')).toBeNull();
    // SVG placeholder presente.
    expect(container.querySelector('svg')).toBeInTheDocument();
  });

  it('respeta fit="contain" vs "cover" via object-fit', () => {
    const { container } = render(
      <ProductImage image={sampleImage} alt="x" fit="contain" />,
    );
    expect(container.querySelector('img')?.className).toContain('object-contain');

    const { container: c2 } = render(
      <ProductImage image={sampleImage} alt="x" fit="cover" />,
    );
    expect(c2.querySelector('img')?.className).toContain('object-cover');
  });
});
