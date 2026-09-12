import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { useForm, type UseFormReturn } from 'react-hook-form';

import { ProductForm } from './ProductForm';
import { type StoreProductInput, type StoreProductValues } from '../schemas';
import { CREATE_PRODUCT_FORM_VISIBILITY } from '../productFormConfig';

vi.mock('@/features/inventory-center/lookups', () => ({
  useBrands: () => ({ data: [] }),
  useCategoriesTree: () => ({
    data: [
      {
        id: 1,
        name: 'Telefonia',
        children: [
          { id: 2, name: 'Smartphones' },
          { id: 3, name: 'Cargadores' },
        ],
      },
    ],
  }),
  useExchangeRateTypes: () => ({ data: [] }),
  useProductImages: () => ({ data: [] }),
  useWarrantyPolicies: () => ({ data: [] }),
}));

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

  it('filtra las categorias por nombre', async () => {
    const user = userEvent.setup();
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

    expect(screen.getByText('Smartphones')).toBeInTheDocument();
    await user.type(screen.getByPlaceholderText(/Buscar categoría/i), 'Cargadores');
    expect(screen.getByText('Cargadores')).toBeInTheDocument();
    expect(screen.queryByText('Smartphones')).not.toBeInTheDocument();
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

  it('respeta CREATE_PRODUCT_FORM_VISIBILITY ocultando campos no esenciales', () => {
    const form = makeForm();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ProductForm
          form={form}
          tagOptions={[]}
          onSubmit={() => undefined}
          isSubmitting={false}
          submitLabel="Crear producto"
          visibility={CREATE_PRODUCT_FORM_VISIBILITY}
          showAdvancedToggle
        />
      </QueryClientProvider>,
    );

    // Esenciales visibles
    expect(screen.getByText(/Nombre/i)).toBeInTheDocument();
    expect(screen.getByPlaceholderText('iPhone 15')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('IPH15-128')).toBeInTheDocument();
    expect(screen.getByPlaceholderText('0194253714750')).toBeInTheDocument();
    expect(screen.getByText('Marca')).toBeInTheDocument();
    expect(screen.getByText('Categorías')).toBeInTheDocument();
    expect(screen.getByText('Precio de venta manual')).toBeInTheDocument();

    // No esenciales ocultos
    expect(screen.queryByText('Tags')).not.toBeInTheDocument();
    expect(screen.queryByText(/Descripción larga/i)).not.toBeInTheDocument();
    expect(screen.queryByText(/Stock mínimo/i)).not.toBeInTheDocument();
    expect(screen.queryByText('Garantía y estado')).not.toBeInTheDocument();

    // Botón de toggle presente
    expect(screen.getByTestId('toggle-advanced-product-fields')).toBeInTheDocument();
    expect(screen.getByText('+ Mostrar más campos (avanzado)')).toBeInTheDocument();
  });

  it('permite desplegar y volver a ocultar los campos avanzados con el toggle', async () => {
    const user = userEvent.setup();
    const form = makeForm();
    render(
      <QueryClientProvider client={new QueryClient()}>
        <ProductForm
          form={form}
          tagOptions={[]}
          onSubmit={() => undefined}
          isSubmitting={false}
          submitLabel="Crear producto"
          visibility={CREATE_PRODUCT_FORM_VISIBILITY}
          showAdvancedToggle
        />
      </QueryClientProvider>,
    );

    const toggleBtn = screen.getByTestId('toggle-advanced-product-fields');
    expect(screen.queryByText('Tags')).not.toBeInTheDocument();

    // Desplegar campos avanzados
    await user.click(toggleBtn);
    expect(screen.getByText('Tags')).toBeInTheDocument();
    expect(screen.getByText('Garantía y estado')).toBeInTheDocument();
    expect(screen.getByText('− Ocultar campos adicionales')).toBeInTheDocument();

    // Volver a ocultar
    await user.click(toggleBtn);
    expect(screen.queryByText('Tags')).not.toBeInTheDocument();
    expect(screen.queryByText('Garantía y estado')).not.toBeInTheDocument();
    expect(screen.getByText('+ Mostrar más campos (avanzado)')).toBeInTheDocument();
  });
});
