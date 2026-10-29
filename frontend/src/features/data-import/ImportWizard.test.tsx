import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockCreateSession = vi.fn();
const mockUpload = vi.fn();
const mockRun = vi.fn();

vi.mock('./api', () => ({
  SUPPORTED_ENTITIES: ['branches', 'products'],
  ENTITY_LABELS: { branches: 'Sucursales', products: 'Productos' },
  templateUrl: (entity: string, current = false) =>
    `/import/templates/${entity}${current ? '?current=1' : ''}`,
  supportsCurrentData: (entity: string) => entity === 'products',
  downloadImportFile: vi.fn(),
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
  mockCreateSession.mockResolvedValue({ id: 1 });
  mockUpload.mockResolvedValue({});
  mockRun.mockResolvedValue({ summary: { total: 0, ok: 0, skipped: 0, failed: 0, status: 'completed' } });
});

describe('<ImportWizard>', () => {
  it('permite subir productos desde CSV o Excel', async () => {
    const { container } = render(<ImportWizard />);

    await userEvent.click(screen.getByRole('button', { name: 'Subir productos' }));

    expect(container.querySelector('input[type="file"]')).toHaveAttribute(
      'accept',
      expect.stringContaining('.xlsx'),
    );
  });

  it('presenta la carga de productos y permite elegir otros datos', async () => {
    const { container } = render(<ImportWizard />);

    expect(screen.getByRole('heading', { name: 'Agregar o actualizar productos' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Subir productos' })).toBeInTheDocument();

    await userEvent.click(screen.getByText('Cargar otro tipo de dato'));
    await userEvent.selectOptions(screen.getByRole('combobox'), 'branches');

    expect(screen.getByText('Sube aquí el CSV completado con la plantilla descargada.')).toBeInTheDocument();
    expect(screen.getByText('Selecciona o arrastra aquí tu archivo CSV')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Descargar plantilla' })).toBeInTheDocument();

    const fileInput = container.querySelector('input[type="file"]');
    expect(fileInput).toBeTruthy();

    await userEvent.upload(fileInput as HTMLInputElement, new File(['code,name\nB1,Sucursal 1\n'], 'branches.csv', { type: 'text/csv' }));

    await waitFor(() => {
      expect(mockCreateSession).toHaveBeenCalledWith({ meta: { entity: 'branches' } });
      expect(mockUpload).toHaveBeenCalledWith({
        file: expect.any(File),
        sessionId: 1,
        entity: 'branches',
      });
    });
  });
});
