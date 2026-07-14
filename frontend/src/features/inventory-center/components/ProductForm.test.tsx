import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useForm, type UseFormReturn } from 'react-hook-form';

import { ProductForm } from './ProductForm';
import { type StoreProductInput, type StoreProductValues } from '../schemas';

function makeForm(initial?: Partial<StoreProductValues>) {
  let captured: UseFormReturn<StoreProductInput, unknown, StoreProductValues> | null = null;
  function FormCapture() {
    captured = useForm<StoreProductInput, unknown, StoreProductValues>({
      defaultValues: {
        name: '',
        sku: '',
        barcode: '',
        description: '',
        long_description: '',
        image_url: '',
        tracking_type: 'quantity',
        unit_of_measure: 'unit',
        track_stock: true,
        category_ids: [],
        tag_ids: [],
        sale_currency: 'USD',
        is_active: true,
        ...initial,
      },
    });
    return null;
  }
  const Wrapper = () => (
    <QueryClientProvider client={new QueryClient()}>
      <FormCapture />
    </QueryClientProvider>
  );
  render(<Wrapper />);
  return captured!;
}

describe('<ProductForm>', () => {
  it('renderiza los 5 fieldsets principales', () => {
    const form = makeForm();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ProductForm
          form={form}
          tagOptions={[]}
          onSubmit={() => undefined}
          isSubmitting={false}
          submitLabel="Guardar"
        />
      </QueryClientProvider>,
    );
    expect(screen.getByText('Identificacion')).toBeInTheDocument();
    expect(screen.getByText('Catálogos')).toBeInTheDocument();
    expect(screen.getByText('Control de stock')).toBeInTheDocument();
    expect(screen.getByText('Precios')).toBeInTheDocument();
    expect(screen.getByText('Garantía y estado')).toBeInTheDocument();
  });

  it('muestra el nombre prellenado en initialValues', () => {
    const form = makeForm({ name: 'iPhone 15' });
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ProductForm
          form={form}
          tagOptions={[]}
          onSubmit={() => undefined}
          isSubmitting={false}
          submitLabel="Guardar"
        />
      </QueryClientProvider>,
    );
    expect(screen.getByDisplayValue('iPhone 15')).toBeInTheDocument();
  });

  it('llama onSubmit al hacer click en el boton submit', async () => {
    const user = userEvent.setup();
    const form = makeForm();
    const onSubmit = vi.fn();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ProductForm
          form={form}
          tagOptions={[]}
          onSubmit={onSubmit}
          isSubmitting={false}
          submitLabel="Guardar"
        />
      </QueryClientProvider>,
    );
    await user.click(screen.getByRole('button', { name: /Guardar/i }));
    expect(onSubmit).toHaveBeenCalled();
  });

  it('muestra el spinner cuando isSubmitting=true', () => {
    const form = makeForm();
    const { container } = render(
      <QueryClientProvider client={new QueryClient()}>
        <ProductForm
          form={form}
          tagOptions={[]}
          onSubmit={() => undefined}
          isSubmitting
          submitLabel="Guardar"
        />
      </QueryClientProvider>,
    );
    // El Button en estado loading muestra un Spinner (svg con class animate-spin).
    expect(container.querySelector('svg.animate-spin')).toBeInTheDocument();
  });
});