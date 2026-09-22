import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import { SupplierAutocomplete } from './SupplierAutocomplete';

const { mockUseSuppliers } = vi.hoisted(() => ({
  mockUseSuppliers: vi.fn(),
}));

vi.mock('@/features/suppliers/api', () => ({
  useSuppliers: mockUseSuppliers,
}));

describe('SupplierAutocomplete', () => {
  beforeEach(() => {
    mockUseSuppliers.mockReturnValue({
      data: [
        { id: 1, name: 'PROVEEDOR UNO', document_type: 'J', document_number: '123' },
        { id: 2, name: 'PROVEEDOR DOS', document_type: 'J', document_number: '456' },
      ],
    });
  });

  it('no abre la lista al enfocar (el formulario abierto no muestra proveedores)', () => {
    render(<SupplierAutocomplete value={null} onChange={vi.fn()} />);

    const input = screen.getByPlaceholderText('Buscar proveedor por nombre o documento...');
    fireEvent.focus(input);

    expect(screen.queryByRole('option')).not.toBeInTheDocument();
  });

  it('abre la lista al hacer click en el campo', () => {
    render(<SupplierAutocomplete value={null} onChange={vi.fn()} />);

    fireEvent.click(screen.getByPlaceholderText('Buscar proveedor por nombre o documento...'));

    expect(screen.getByRole('option', { name: /PROVEEDOR UNO/i })).toBeInTheDocument();
  });

  it('filtra por nombre y documento', () => {
    render(<SupplierAutocomplete value={null} onChange={vi.fn()} />);

    const input = screen.getByPlaceholderText('Buscar proveedor por nombre o documento...');
    fireEvent.click(input);
    fireEvent.change(input, { target: { value: 'proveedor dos' } });

    expect(screen.getByRole('option', { name: /PROVEEDOR DOS/i })).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: /PROVEEDOR UNO/i })).not.toBeInTheDocument();
  });
});
