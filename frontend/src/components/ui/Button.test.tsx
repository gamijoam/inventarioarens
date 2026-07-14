import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Button } from './Button';

describe('<Button>', () => {
  it('renderiza el children', () => {
    render(<Button>Crear producto</Button>);
    expect(screen.getByRole('button', { name: 'Crear producto' })).toBeInTheDocument();
  });

  it('aplica variant primary por defecto', () => {
    render(<Button>Test</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-primary');
  });

  it('aplica variant danger cuando se pide', () => {
    render(<Button variant="danger">Eliminar</Button>);
    const btn = screen.getByRole('button');
    expect(btn.className).toContain('bg-danger');
  });

  it('se deshabilita cuando loading=true', () => {
    render(<Button loading>Guardando</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });

  it('se deshabilita cuando disabled=true', () => {
    render(<Button disabled>Inactivo</Button>);
    expect(screen.getByRole('button')).toBeDisabled();
  });
});