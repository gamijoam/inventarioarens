/**
 * Tests del boton "Descargar imagen de una URL" de ImageGallery (opcion B).
 * Soporta varias URLs (una por linea) via mutateAsync.
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ImageGallery } from '../ImageGallery';

const mutateAsync = vi.fn();

vi.mock('@/features/inventory-center/api', () => ({
  useUploadProductImage: () => ({ mutate: vi.fn(), isPending: false }),
  useUploadProductImageFromUrl: () => ({ mutateAsync, isPending: false }),
  useUpdateProductImage: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteProductImage: () => ({ mutate: vi.fn(), isPending: false }),
  useReorderProductImages: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: () => null,
}));

describe('ImageGallery — descargar de URL', () => {
  beforeEach(() => {
    mutateAsync.mockReset();
  });

  it('abre el textarea al hacer click y descarga varias URLs', async () => {
    mutateAsync.mockResolvedValue({ id: 1 });

    render(<ImageGallery productId={1} images={[]} canEdit />);
    fireEvent.click(screen.getByTestId('open-from-url'));

    const input = screen.getByTestId('from-url-input') as HTMLTextAreaElement;
    fireEvent.change(input, {
      target: {
        value: 'https://cdn.example.com/a.jpg\nhttps://cdn.example.com/b.jpg',
      },
    });

    fireEvent.click(screen.getByTestId('from-url-submit'));

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledTimes(2);
      expect(mutateAsync).toHaveBeenCalledWith({ url: 'https://cdn.example.com/a.jpg' });
      expect(mutateAsync).toHaveBeenCalledWith({ url: 'https://cdn.example.com/b.jpg' });
    });
  });

  it('ignora lineas vacias', async () => {
    mutateAsync.mockResolvedValue({ id: 1 });

    render(<ImageGallery productId={1} images={[]} canEdit />);
    fireEvent.click(screen.getByTestId('open-from-url'));

    const input = screen.getByTestId('from-url-input') as HTMLTextAreaElement;
    fireEvent.change(input, {
      target: { value: 'https://cdn.example.com/a.jpg\n\n   \nhttps://cdn.example.com/b.jpg' },
    });

    fireEvent.click(screen.getByTestId('from-url-submit'));

    await waitFor(() => {
      expect(mutateAsync).toHaveBeenCalledTimes(2);
    });
  });

  it('no descarga cuando el textarea esta vacio', () => {
    render(<ImageGallery productId={1} images={[]} canEdit />);
    fireEvent.click(screen.getByTestId('open-from-url'));
    const submit = screen.getByTestId('from-url-submit') as HTMLButtonElement;
    expect(submit).toBeDisabled();
  });
});
