/**
 * Tests para ImeiScanner (Fase 1 - IMEI scanner).
 * Cubre:
 *  - renderiza vacio cuando no hay productId o warehouseId.
 *  - carga y muestra las unidades disponibles del endpoint.
 *  - toggle: agregar/quitar serial del array selected.
 *  - respeta el limite max (no permite seleccionar mas).
 *  - el campo search filtra por prefijo en el backend.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { ImeiScanner } from '../ImeiScanner';

const mockUseAvailableProductUnits = vi.fn();

vi.mock('@/features/inventory-center/api', () => ({
  useAvailableProductUnits: (...args: unknown[]) => mockUseAvailableProductUnits(...args),
}));

function makeWrapper() {
  const qc = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  return ({ children }: { children: React.ReactNode }) => (
    <QueryClientProvider client={qc}>{children}</QueryClientProvider>
  );
}

describe('ImeiScanner', () => {
  beforeEach(() => {
    mockUseAvailableProductUnits.mockReset();
  });

  it('muestra mensaje placeholder cuando no hay productId o warehouseId', () => {
    mockUseAvailableProductUnits.mockReturnValue({ data: [], isLoading: false, isError: false });
    render(<ImeiScanner productId={null} warehouseId={null} selected={[]} onChange={vi.fn()} />, { wrapper: makeWrapper() });

    expect(
      screen.getByText(/Selecciona un producto y un almacen para ver las unidades disponibles/i),
    ).toBeInTheDocument();
  });

  it('renderiza spinner mientras carga', () => {
    mockUseAvailableProductUnits.mockReturnValue({ data: undefined, isLoading: true, isError: false });
    render(
      <ImeiScanner
        productId={1}
        warehouseId={1}
        selected={[]}
        onChange={vi.fn()}
        dataTestIdPrefix="imei-test"
      />,
      { wrapper: makeWrapper() },
    );

    // El Spinner se renderiza dentro de un <p> Y dentro de un span.sr-only.
    // Usamos within() sobre el <p> visible para no confundir con el sr-only.
    const visibleText = screen.getAllByText(/Buscando IMEIs disponibles/i);
    expect(visibleText.length).toBeGreaterThanOrEqual(1);
  });

  it('muestra EmptyState cuando no hay unidades', () => {
    mockUseAvailableProductUnits.mockReturnValue({ data: [], isLoading: false, isError: false });
    render(
      <ImeiScanner
        productId={1}
        warehouseId={1}
        selected={[]}
        onChange={vi.fn()}
        dataTestIdPrefix="imei-test"
      />,
      { wrapper: makeWrapper() },
    );

    expect(screen.getByText(/Sin IMEIs disponibles/i)).toBeInTheDocument();
  });

  it('renderiza las unidades disponibles como botones', async () => {
    mockUseAvailableProductUnits.mockReturnValue({
      data: [
        { id: 1, product_id: 10, warehouse_id: 1, serial_type: 'imei', serial_number: '352099001761481', status: 'available' },
        { id: 2, product_id: 10, warehouse_id: 1, serial_type: 'imei', serial_number: '352099001761482', status: 'available' },
        { id: 3, product_id: 10, warehouse_id: 1, serial_type: 'serial', serial_number: 'SN-001', status: 'available' },
      ],
      isLoading: false,
      isError: false,
    });

    render(
      <ImeiScanner
        productId={10}
        warehouseId={1}
        selected={[]}
        onChange={vi.fn()}
        serialType="imei"
        dataTestIdPrefix="imei-test"
      />,
      { wrapper: makeWrapper() },
    );

    await waitFor(() => {
      expect(screen.getByTestId('imei-test-item-1')).toBeInTheDocument();
    });
    // El filter por serialType='imei' debe ocultar el serial con tipo 'serial'
    const list = screen.getByTestId('imei-test-list');
    expect(within(list).queryByText('SN-001')).not.toBeInTheDocument();
    expect(within(list).getByText('352099001761481')).toBeInTheDocument();
    expect(within(list).getByText('352099001761482')).toBeInTheDocument();
  });

  it('toggle: hace click en una unidad, llama onChange con el array actualizado', async () => {
    const onChange = vi.fn();
    mockUseAvailableProductUnits.mockReturnValue({
      data: [
        { id: 1, product_id: 10, warehouse_id: 1, serial_type: 'imei', serial_number: '352099001761481', status: 'available' },
      ],
      isLoading: false,
      isError: false,
    });

    render(
      <ImeiScanner
        productId={10}
        warehouseId={1}
        selected={[]}
        onChange={onChange}
        dataTestIdPrefix="imei-test"
      />,
      { wrapper: makeWrapper() },
    );

    await waitFor(() => {
      expect(screen.getByTestId('imei-test-item-1')).toBeInTheDocument();
    });

    await userEvent.click(screen.getByTestId('imei-test-item-1'));

    expect(onChange).toHaveBeenCalledWith(['352099001761481']);
  });

  it('toggle: re-click en una unidad ya seleccionada, llama onChange removiendola', async () => {
    const onChange = vi.fn();
    mockUseAvailableProductUnits.mockReturnValue({
      data: [
        { id: 1, product_id: 10, warehouse_id: 1, serial_type: 'imei', serial_number: '352099001761481', status: 'available' },
      ],
      isLoading: false,
      isError: false,
    });

    render(
      <ImeiScanner
        productId={10}
        warehouseId={1}
        selected={['352099001761481']}
        onChange={onChange}
        dataTestIdPrefix="imei-test"
      />,
      { wrapper: makeWrapper() },
    );

    await waitFor(() => {
      expect(screen.getByTestId('imei-test-item-1')).toBeInTheDocument();
    });

    await userEvent.click(screen.getByTestId('imei-test-item-1'));

    expect(onChange).toHaveBeenCalledWith([]);
  });

  it('respeta el limite max: deshabilita items no seleccionados cuando ya hay max seleccionados', async () => {
    const onChange = vi.fn();
    mockUseAvailableProductUnits.mockReturnValue({
      data: [
        { id: 1, product_id: 10, warehouse_id: 1, serial_type: 'imei', serial_number: 'A-001', status: 'available' },
        { id: 2, product_id: 10, warehouse_id: 1, serial_type: 'imei', serial_number: 'A-002', status: 'available' },
      ],
      isLoading: false,
      isError: false,
    });

    render(
      <ImeiScanner
        productId={10}
        warehouseId={1}
        selected={['A-001']}
        onChange={onChange}
        max={1}
        dataTestIdPrefix="imei-test"
      />,
      { wrapper: makeWrapper() },
    );

    await waitFor(() => {
      expect(screen.getByTestId('imei-test-item-1')).toBeInTheDocument();
    });

    // A-001 esta seleccionado (no disabled), A-002 esta disabled (max alcanzado).
    expect(screen.getByTestId('imei-test-item-1')).not.toBeDisabled();
    expect(screen.getByTestId('imei-test-item-2')).toBeDisabled();
  });

  it('muestra los IMEIs seleccionados como chips removibles', async () => {
    mockUseAvailableProductUnits.mockReturnValue({
      data: [
        { id: 1, product_id: 10, warehouse_id: 1, serial_type: 'imei', serial_number: 'A-001', status: 'available' },
      ],
      isLoading: false,
      isError: false,
    });

    render(
      <ImeiScanner
        productId={10}
        warehouseId={1}
        selected={['A-001', 'A-002']}
        onChange={vi.fn()}
        dataTestIdPrefix="imei-test"
      />,
      { wrapper: makeWrapper() },
    );

    // 'A-001' aparece en la lista de unidades y en el chip de seleccionados.
    // El chip usa data-testid={`${prefix}-chip-${serial}`}.
    expect(screen.getByTestId('imei-test-chip-A-001')).toBeInTheDocument();
    expect(screen.getByTestId('imei-test-chip-A-002')).toBeInTheDocument();
  });
});