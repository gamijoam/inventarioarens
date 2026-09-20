import { act, renderHook } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { useProductForm } from '../forms';

vi.mock('../api', () => ({
  useCreateProduct: () => ({ mutateAsync: vi.fn(), isPending: false, error: null }),
  useUpdateProduct: () => ({ mutateAsync: vi.fn(), isPending: false, error: null }),
}));

describe('useProductForm', () => {
  it('resetForm restaura los defaults y permite precargar el nombre', () => {
    const { result } = renderHook(() => useProductForm({ mode: 'create' }));

    act(() => {
      result.current.form.setValue('name', 'Producto anterior');
    });
    expect(result.current.form.getValues('name')).toBe('Producto anterior');

    act(() => {
      result.current.resetForm();
    });
    expect(result.current.form.getValues('name')).toBe('');
    expect(result.current.form.getValues('tracking_type')).toBe('quantity');

    act(() => {
      result.current.resetForm({ name: 'Producto nuevo' });
    });
    expect(result.current.form.getValues('name')).toBe('Producto nuevo');
  });
});
