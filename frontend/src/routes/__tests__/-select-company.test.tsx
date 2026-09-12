import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const mockNavigate = vi.fn();
const mockSelectCompany = vi.fn();
const mockSignOut = vi.fn();

vi.mock('@tanstack/react-router', () => ({
  createFileRoute: () => () => ({}),
  useNavigate: () => mockNavigate,
}));

vi.mock('@/auth/useAuth', () => ({
  useAuth: () => ({
    selectCompany: mockSelectCompany,
    signOut: mockSignOut,
  }),
}));

const mockSession = {
  user: { id: 1, email: 'user@test.com', name: 'Carlos Gomez' },
  tenant: null as any,
  pendingTenants: [
    { id: 10, slug: 'empresa-alfa', name: 'Empresa Alfa' },
    { id: 20, slug: 'empresa-beta', name: 'Empresa Beta' },
  ],
  roles: ['Vendedor'],
  permissions: new Set(['pos.view']),
};

vi.mock('@/stores/session', () => ({
  useSessionStore: Object.assign(
    (selector: any) => selector(mockSession),
    {
      getState: () => mockSession,
    },
  ),
}));

import { SelectCompanyPage } from '../select-company';

describe('<SelectCompanyPage>', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders title, user greeting, and the list of pending companies', () => {
    render(<SelectCompanyPage />);

    expect(screen.getByText('Selecciona tu empresa')).toBeInTheDocument();
    expect(screen.getByText('Carlos Gomez')).toBeInTheDocument();
    expect(screen.getByTestId('company-item-empresa-alfa')).toBeInTheDocument();
    expect(screen.getByTestId('company-item-empresa-beta')).toBeInTheDocument();
    expect(screen.getByText('Empresa Alfa')).toBeInTheDocument();
    expect(screen.getByText('Empresa Beta')).toBeInTheDocument();
  });

  it('calls selectCompany with selected slug and navigates on success', async () => {
    mockSelectCompany.mockResolvedValueOnce(undefined);

    render(<SelectCompanyPage />);

    const alfaBtn = screen.getByTestId('company-item-empresa-alfa');
    fireEvent.click(alfaBtn);

    await vi.waitFor(() => {
      expect(mockSelectCompany).toHaveBeenCalledWith('empresa-alfa');
      expect(mockNavigate).toHaveBeenCalledWith({ to: '/dashboard' });
    });
  });

  it('displays error alert when company selection fails', async () => {
    mockSelectCompany.mockRejectedValueOnce(new Error('No autorizado en esta empresa'));

    render(<SelectCompanyPage />);

    const betaBtn = screen.getByTestId('company-item-empresa-beta');
    fireEvent.click(betaBtn);

    await vi.waitFor(() => {
      expect(screen.getByText('No pudimos cambiar de empresa')).toBeInTheDocument();
      expect(screen.getByText('No autorizado en esta empresa')).toBeInTheDocument();
    });
  });

  it('calls signOut and navigates to /login when clicking sign out button', async () => {
    mockSignOut.mockResolvedValueOnce(undefined);

    render(<SelectCompanyPage />);

    const signoutBtn = screen.getByTestId('select-company-signout');
    fireEvent.click(signoutBtn);

    await vi.waitFor(() => {
      expect(mockSignOut).toHaveBeenCalled();
      expect(mockNavigate).toHaveBeenCalledWith({ to: '/login' });
    });
  });
});
