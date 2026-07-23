import { beforeEach, describe, expect, it, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockCreateSession = vi.fn();
const mockUpload = vi.fn();
const mockRun = vi.fn();

vi.mock('./api', () => ({
  SUPPORTED_ENTITIES: ['branches', 'products'],
  ENTITY_LABELS: { branches: 'Sucursales', products: 'Productos' },
  templateUrl: (entity: string) => `/import/templates/${entity}`,
  downloadImportFile: vi.fn(),
  useCreateDataImportSession: () => ({ mutateAsync: mockCreateSession, isPending: false }),
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
  it('muestra la zona de subida al elegir una entidad', async () => {
    const { container } = render(<ImportWizard />);

    await userEvent.selectOptions(screen.getByRole('combobox'), 'products');

    expect(screen.getByText('Sube aqui el CSV ya completado usando la plantilla del tipo elegido.')).toBeInTheDocument();
    expect(screen.getByText('Arrastra tu CSV aqui o haz click para seleccionar')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Descargar plantilla' })).toBeInTheDocument();

    const fileInput = container.querySelector('input[type="file"]');
    expect(fileInput).toBeTruthy();

    await userEvent.upload(fileInput as HTMLInputElement, new File(['sku,name\nP1,Producto 1\n'], 'products.csv', { type: 'text/csv' }));

    await waitFor(() => {
      expect(mockCreateSession).toHaveBeenCalledWith({ meta: { entity: 'products' } });
      expect(mockUpload).toHaveBeenCalledWith({
        file: expect.any(File),
        sessionId: 1,
        entity: 'products',
      });
    });
  });
});
