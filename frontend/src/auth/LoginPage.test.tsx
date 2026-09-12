import { render, screen, fireEvent } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

const mockNavigate = vi.fn();
const mockSignIn = vi.fn();

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to, ...props }: { children: string; to: string }) => (
    <a href={to} {...props}>
      {children}
    </a>
  ),
  useNavigate: () => mockNavigate,
}));

vi.mock('@/api/endpoints/auth', () => ({
  getPublicTenant: vi.fn().mockResolvedValue(null),
}));

vi.mock('@/auth/useAuth', () => ({
  useAuth: () => ({
    isAuthenticated: false,
    signIn: mockSignIn,
  }),
}));

vi.mock('@/stores/session', () => ({
  useSessionStore: {
    getState: () => ({
      roles: ['Administrador'],
      permissions: new Set(['products.view']),
      tenant: { id: 1, slug: 'mi-empresa' },
      pendingTenants: [],
      clearSession: vi.fn(),
    }),
  },
}));

import { LoginPage } from './LoginPage';

describe('<LoginPage>', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders login without tenant selector and button is disabled when inputs are empty', () => {
    render(<LoginPage />);

    expect(screen.getByTestId('login-page')).toHaveAttribute('data-app-mode', 'admin');
    expect(screen.getByText('SDI')).toBeInTheDocument();
    expect(screen.getByText('SISTEMA DE INVENTARIO')).toBeInTheDocument();
    expect(screen.getByTestId('login-submit')).toHaveTextContent('LOGIN');
    expect(screen.getByTestId('login-submit')).toBeDisabled();
    expect(screen.getByTestId('login-forgot')).toBeInTheDocument();

    // El selector previo de empresas ya NO debe existir
    expect(screen.queryByTestId('login-tenant')).toBeNull();
  });

  it('enables submit button when email and password are provided', () => {
    render(<LoginPage />);

    const emailInput = screen.getByTestId('login-email');
    const passwordInput = screen.getByTestId('login-password');
    const submitBtn = screen.getByTestId('login-submit');

    expect(submitBtn).toBeDisabled();

    fireEvent.change(emailInput, { target: { value: 'user@test.com' } });
    expect(submitBtn).toBeDisabled();

    fireEvent.change(passwordInput, { target: { value: 'secret123' } });
    expect(submitBtn).not.toBeDisabled();
  });

  it('navigates to /select-company when user belongs to multiple companies', async () => {
    mockSignIn.mockResolvedValueOnce({
      requiresSelection: true,
      tenants: [
        { id: 1, slug: 'empresa-a', name: 'Empresa A' },
        { id: 2, slug: 'empresa-b', name: 'Empresa B' },
      ],
    });

    render(<LoginPage />);

    fireEvent.change(screen.getByTestId('login-email'), { target: { value: 'multi@test.com' } });
    fireEvent.change(screen.getByTestId('login-password'), { target: { value: 'secret123' } });
    fireEvent.click(screen.getByTestId('login-submit'));

    await vi.waitFor(() => {
      expect(mockSignIn).toHaveBeenCalledWith({
        email: 'multi@test.com',
        password: 'secret123',
        device_name: expect.any(String),
      });
      expect(mockNavigate).toHaveBeenCalledWith({ to: '/select-company' });
    });
  });

  it('navigates directly to post-login route when user belongs to a single company', async () => {
    mockSignIn.mockResolvedValueOnce({
      requiresSelection: false,
    });

    render(<LoginPage />);

    fireEvent.change(screen.getByTestId('login-email'), { target: { value: 'single@test.com' } });
    fireEvent.change(screen.getByTestId('login-password'), { target: { value: 'secret123' } });
    fireEvent.click(screen.getByTestId('login-submit'));

    await vi.waitFor(() => {
      expect(mockSignIn).toHaveBeenCalledWith({
        email: 'single@test.com',
        password: 'secret123',
        device_name: expect.any(String),
      });
      expect(mockNavigate).toHaveBeenCalledWith({ to: '/dashboard' });
    });
  });

  it('displays alert message when login fails', async () => {
    const error = new Error('Credenciales inválidas');
    (error as any).status = 401;
    mockSignIn.mockRejectedValueOnce(error);

    render(<LoginPage />);

    fireEvent.change(screen.getByTestId('login-email'), { target: { value: 'fail@test.com' } });
    fireEvent.change(screen.getByTestId('login-password'), { target: { value: 'wrongpass' } });
    fireEvent.click(screen.getByTestId('login-submit'));

    await vi.waitFor(() => {
      expect(screen.getByText('No pudimos iniciar sesión')).toBeInTheDocument();
      expect(screen.getByText('Email o contraseña incorrectos.')).toBeInTheDocument();
    });
  });
});
