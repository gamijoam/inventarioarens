import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { usePosTap } from '../usePosTap';

describe('usePosTap (use-gesture)', () => {
  it('devuelve una funcion bind esparcible sobre un elemento', () => {
    let bind: ReturnType<typeof usePosTap> | null = null;
    const onTap = vi.fn();

    function Probe() {
      bind = usePosTap(onTap);
      return <button type="button" data-testid="target" {...bind()} />;
    }

    render(<Probe />);

    expect(bind).toBeTypeOf('function');
    // El bind es esparcible sobre el boton sin lanzar.
    expect(screen.getByTestId('target')).toBeInTheDocument();
  });

  it('el boton sigue siendo operable con click de mouse (onClick no se elimina)', () => {
    const onTap = vi.fn();
    const onClick = vi.fn();

    function Probe() {
      const bind = usePosTap(onTap);
      return (
        <button type="button" data-testid="target" {...bind()} onClick={onClick}>
          Agregar
        </button>
      );
    }

    render(<Probe />);
    screen.getByTestId('target').click();

    // use-gesture maneja el tap; el onClick nativo sigue disponible como
    // respaldo para mouse/teclado sin romperse por el bind.
    expect(screen.getByTestId('target')).toBeInTheDocument();
    expect(onClick).toHaveBeenCalled();
  });
});
