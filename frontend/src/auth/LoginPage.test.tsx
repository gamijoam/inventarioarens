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
  it('renders the administrative presentation without changing the login contract', () => {
    render(<LoginPage />);

    expect(screen.getByTestId('login-page')).toHaveAttribute('data-app-mode', 'admin');
    expect(screen.getByText('Control administrativo')).toBeInTheDocument();
    expect(
      screen.getByRole('heading', { name: 'Entra a tu espacio de trabajo' }),
    ).toBeInTheDocument();
    expect(screen.getByTestId('login-submit')).toHaveTextContent('Entrar al sistema');
    expect(screen.getByTestId('login-submit')).toBeDisabled();
  });
});
