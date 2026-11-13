import type * as PackagingApiModule from '../../packagingApi';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { ValidationError } from '@/types/api';
import { ProductPackagingsTab } from '../ProductPackagingsTab';

const createAsync = vi.fn();
const deleteAsync = vi.fn();
const toastError = vi.fn<(message: string) => void>();
const toastSuccess = vi.fn<(message: string) => void>();
let canEdit = true;

vi.mock('@/lib/toast', () => ({
  toast: {
    error: (m: string) => {
      toastError(m);
    },
    success: (m: string) => {
      toastSuccess(m);
    },
  },
}));
vi.mock('@/permissions/useCan', () => ({ useCan: () => canEdit }));
vi.mock('../../packagingApi', async (importOriginal) => ({
  ...(await importOriginal<typeof PackagingApiModule>()),
  useProductPackagings: () => ({
    isLoading: false,
    data: [{ id: 5, product_id: 1, name: 'Caja x24', factor: 24, barcode: '759024', price_usd: 24, unit_price_usd: 1, is_active: true }],
  }),
  useCreatePackaging: () => ({ mutateAsync: createAsync, isPending: false }),
  useDeletePackaging: () => ({ mutateAsync: deleteAsync, isPending: false }),
}));

describe('ProductPackagingsTab', () => {
  beforeEach(() => {
    createAsync.mockReset();
    deleteAsync.mockReset();
    toastError.mockReset();
    toastSuccess.mockReset();
    canEdit = true;
  });

  it('lista las presentaciones con su factor y precio unitario', () => {
    render(<ProductPackagingsTab productId={1} />);
    expect(screen.getByText('Caja x24')).toBeInTheDocument();
    expect(screen.getByText(/24 unidades/)).toBeInTheDocument();
  });

  it('crea una presentacion', async () => {
    createAsync.mockResolvedValue({});
    render(<ProductPackagingsTab productId={1} />);
    await userEvent.type(screen.getByLabelText(/Nombre/i), 'Bulto x12');
    await userEvent.type(screen.getByLabelText(/Unidades/i), '12');
    await userEvent.type(screen.getByLabelText(/Precio propio/i), '11');
    await userEvent.click(screen.getByRole('button', { name: /Agregar presentaci/i }));

    await waitFor(() =>
      expect(createAsync).toHaveBeenCalledWith({ productId: 1, values: { name: 'Bulto x12', factor: 12, barcode: null, price_usd: 11 } }),
    );
    expect(toastSuccess).toHaveBeenCalledWith('Presentacion agregada.');
  });

  it('muestra el mensaje del backend si se rechaza', async () => {
    const message = 'Ese codigo de barras ya pertenece a un producto.';
    createAsync.mockRejectedValue(new ValidationError(message, { barcode: [message] }));
    render(<ProductPackagingsTab productId={1} />);
    await userEvent.type(screen.getByLabelText(/Nombre/i), 'Caja');
    await userEvent.type(screen.getByLabelText(/Unidades/i), '6');
    await userEvent.click(screen.getByRole('button', { name: /Agregar presentaci/i }));
    await waitFor(() => expect(toastError).toHaveBeenCalledWith(message));
  });

  it('sin permiso solo muestra la lista', () => {
    canEdit = false;
    render(<ProductPackagingsTab productId={1} />);
    expect(screen.getByText('Caja x24')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Agregar presentaci/i })).not.toBeInTheDocument();
  });
});
