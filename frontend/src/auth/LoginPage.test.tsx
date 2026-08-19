import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to, ...props }: { children: string; to: string }) => (
    <a href={to} {...props}>
      {children}
    </a>
  ),
  useNavigate: () => vi.fn(),
}));

vi.mock('@/api/endpoints/auth', () => ({
  lookupTenants: vi.fn(),
}));

vi.mock('@/auth/useAuth', () => ({
  useAuth: () => ({
    isAuthenticated: false,
    signIn: vi.fn(),
  }),
}));

import { LoginPage } from './LoginPage';

describe('<LoginPage>', () => {
  it('renders the redesigned login keeping the contract and testids', () => {
    render(<LoginPage />);

    expect(screen.getByTestId('login-page')).toHaveAttribute('data-app-mode', 'admin');
    expect(screen.getByText('SDI')).toBeInTheDocument();
    expect(screen.getByText('SISTEMA DE INVENTARIO')).toBeInTheDocument();
    expect(screen.getByTestId('login-submit')).toHaveTextContent('LOGIN');
    expect(screen.getByTestId('login-submit')).toBeDisabled();
    expect(screen.getByTestId('login-forgot')).toBeInTheDocument();
  });
});
