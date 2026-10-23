import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockCreateSession = vi.fn();
const mockUpload = vi.fn();
const mockRun = vi.fn();
const mockDownload = vi.hoisted(() => vi.fn());

vi.mock('./api', () => ({
  SUPPORTED_ENTITIES: ['branches', 'products'],
  ENTITY_LABELS: { branches: 'Sucursales', products: 'Productos' },
  templateUrl: (entity: string, current = false, simple = false) => {
    const params = new URLSearchParams();
    if (current) params.set('current', '1');
    if (simple) params.set('simple', '1');
    const query = params.toString();
    return `/import/templates/${entity}${query ? `?${query}` : ''}`;
  },
  supportsCurrentData: (entity: string) => entity === 'products',
  downloadImportFile: mockDownload,
  useCreateDataImportSession: () => ({ mutateAsync: mockCreateSession, isPending: false }),
  useDataImportSession: () => ({ data: undefined }),
  useUploadImportFile: () => ({ mutateAsync: mockUpload, isPending: false }),
  useRunImportEntity: () => ({ mutateAsync: mockRun, isPending: false, data: null }),
}));

import { ImportWizard } from './ImportWizard';

beforeEach(() => {
  mockCreateSession.mockReset();
  mockUpload.mockReset();
  mockRun.mockReset();
  mockDownload.mockReset();
  mockCreateSession.mockResolvedValue({ id: 1 });
  mockUpload.mockResolvedValue({});
  mockRun.mockResolvedValue({
    summary: { total: 0, ok: 0, skipped: 0, failed: 0, status: 'completed' },
  });
});

describe('<ImportWizard>', () => {
  it('muestra la zona de subida al elegir una entidad', async () => {
    const { container } = render(<ImportWizard />);

    await userEvent.click(screen.getByRole('button', { name: 'Subir productos' }));

    expect(
      screen.getByText('Agrega o actualiza productos desde una hoja sencilla.'),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Si ya existe, se actualiza; si es nuevo, se agrega/),
    ).toBeInTheDocument();
    expect(
      screen.getByText('Selecciona o arrastra aquí tu archivo Excel o CSV'),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Plantilla sencilla' })).toBeInTheDocument();
    expect(
      screen.getByRole('button', { name: 'Productos actuales para editar' }),
    ).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: 'Plantilla sencilla' }));
    await userEvent.click(screen.getByRole('button', { name: 'Productos actuales para editar' }));
    expect(mockDownload).toHaveBeenNthCalledWith(
      1,
      '/import/templates/products?simple=1',
      'plantilla_productos_sencilla.csv',
    );
    expect(mockDownload).toHaveBeenNthCalledWith(
      2,
      '/import/templates/products?current=1&simple=1',
      'productos_para_actualizar.csv',
    );

    const fileInput = container.querySelector('input[type="file"]');
    expect(fileInput).toBeTruthy();

    await userEvent.upload(
      fileInput as HTMLInputElement,
      new File(['codigo,nombre,precio\nP1,Producto 1,10\n'], 'products.csv', { type: 'text/csv' }),
    );

    await waitFor(() => {
      expect(mockCreateSession).toHaveBeenCalledWith({ meta: { entity: 'products' } });
      expect(mockUpload).toHaveBeenCalledWith({
        file: expect.any(File),
        sessionId: 1,
        entity: 'products',
      });
    });

    expect(await screen.findByText('codigo')).toBeInTheDocument();
    expect(screen.getByText('P1')).toBeInTheDocument();
  });
});
