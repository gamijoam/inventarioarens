import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const apiMock = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}));

const permissions = vi.hoisted(() => new Set<string>(['settings.manage']));

vi.mock('@/api/client', () => ({ api: apiMock }));
vi.mock('@/permissions/PermissionContext', () => ({
  usePermissionContext: () => ({ permissions }),
}));
vi.mock('@/stores/session', () => ({
  useSessionStore: (selector: (state: unknown) => unknown) => selector({
    tenant: { slug: 'balanzapro', name: 'Balanza Pro' },
  }),
}));

import { OfflineSetupDownloads } from './OfflineSetupDownloads';

describe('OfflineSetupDownloads', () => {
  beforeEach(() => {
    apiMock.get.mockReset();
    apiMock.post.mockReset();
    permissions.clear();
    permissions.add('settings.manage');
    apiMock.get.mockResolvedValue({ data: { available: false } });
  });

  it('no ofrece el instalador mientras no exista un artefacto verificado', async () => {
    render(<OfflineSetupDownloads />);

    expect(await screen.findByTestId('installer-unavailable')).toHaveTextContent(
      'El instalador todavía no está disponible para descargar.',
    );
    expect(screen.getByRole('button', { name: 'Descarga no disponible' })).toBeDisabled();
    expect(screen.queryByTestId('download-setup-btn')).not.toBeInTheDocument();
  });

  it('solicita un paquete para la empresa activa y muestra su estado', async () => {
    apiMock.post.mockResolvedValue({
      data: {
        id: 'b61692a8-14b5-4f6c-9de8-2489bc85a4c9',
        status: 'queued',
        message: 'Preparando los datos de la empresa.',
      },
    });

    render(<OfflineSetupDownloads />);
    fireEvent.click(await screen.findByTestId('create-offline-package-btn'));

    await waitFor(() => {
      expect(apiMock.post).toHaveBeenCalledWith('/offline/snapshots', {});
      expect(screen.getByTestId('offline-package-status')).toHaveTextContent(
        'Preparando los datos de la empresa.',
      );
    });
  });

  it('oculta descargas para quien no tiene permiso de administración', () => {
    permissions.clear();
    const { container } = render(<OfflineSetupDownloads />);
    expect(container).toBeEmptyDOMElement();
  });
});
