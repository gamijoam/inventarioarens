/**
 * Tests del boton "Descargar imagen de una URL" de ImageGallery (opcion B).
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { ImageGallery } from '../ImageGallery';

const mutate = vi.fn();
const uploadPending = false;

vi.mock('@/features/inventory-center/api', () => ({
  useUploadProductImage: () => ({ mutate: vi.fn(), isPending: false }),
  useUploadProductImageFromUrl: () => ({ mutate, isPending: uploadPending }),
  useUpdateProductImage: () => ({ mutate: vi.fn(), isPending: false }),
  useDeleteProductImage: () => ({ mutate: vi.fn(), isPending: false }),
  useReorderProductImages: () => ({ mutate: vi.fn(), isPending: false }),
}));

vi.mock('@/components/ui/ConfirmDialog', () => ({
  ConfirmDialog: () => null,
}));

describe('ImageGallery — descargar de URL', () => {
  it('abre el input de URL al hacer click y descarga al enviar', async () => {
    render(<ImageGallery productId={1} images={[]} canEdit />);

    const openBtn = screen.getByTestId('open-from-url');
    fireEvent.click(openBtn);

    const input = screen.getByTestId('from-url-input') as HTMLInputElement;
    fireEvent.change(input, { target: { value: 'https://cdn.example.com/foto.jpg' } });

    fireEvent.click(screen.getByTestId('from-url-submit'));

    await waitFor(() => {
      expect(mutate).toHaveBeenCalledWith(
        { url: 'https://cdn.example.com/foto.jpg' },
        expect.anything(),
      );
    });
  });

  it('no descarga cuando la URL esta vacia', () => {
    render(<ImageGallery productId={1} images={[]} canEdit />);
    fireEvent.click(screen.getByTestId('open-from-url'));
    const submit = screen.getByTestId('from-url-submit') as HTMLButtonElement;
    expect(submit).toBeDisabled();
  });
});
