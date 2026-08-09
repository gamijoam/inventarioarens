import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { usePosTap } from '../usePosTap';

describe('usePosTap (tap tactil con respaldo pointerdown)', () => {
  it('devuelve bind esparcible y fire para deduplicar', () => {
    let result!: ReturnType<typeof usePosTap>;
    const onTap = vi.fn();

    function Probe() {
      result = usePosTap(onTap);
      return <button type="button" data-testid="target" {...result.bind()} />;
    }

    render(<Probe />);

    expect(result?.bind).toBeTypeOf('function');
    expect(result?.fire).toBeTypeOf('function');
    expect(screen.getByTestId('target')).toBeInTheDocument();
  });

  it('dispara la accion inmediatamente en pointerdown tactil (cubre el pointercancel de Android al cerrar el teclado)', () => {
    const onTap = vi.fn();
    let result!: ReturnType<typeof usePosTap>;

    function Probe() {
      result = usePosTap(onTap);
      return <button type="button" data-testid="target" {...result.bind()} />;
    }

    render(<Probe />);

    const handlers = result?.bind() ?? {};
    handlers.onPointerDown?.({ pointerType: 'touch', preventDefault: () => {} });
    expect(onTap).toHaveBeenCalledTimes(1);
  });

  it('no dispara en pointerdown de mouse (deja que el onClick/use-gesture manejen)', () => {
    const onTap = vi.fn();
    let result!: ReturnType<typeof usePosTap>;

    function Probe() {
      result = usePosTap(onTap);
      return <button type="button" data-testid="target" {...result.bind()} />;
    }

    render(<Probe />);

    const handlers = result?.bind() ?? {};
    handlers.onPointerDown?.({ pointerType: 'mouse', preventDefault: () => {} });
    expect(onTap).not.toHaveBeenCalled();
  });

  it('respeta el estado disabled en el pointerdown inmediato', () => {
    const onTap = vi.fn();
    let result!: ReturnType<typeof usePosTap>;

    function Probe() {
      result = usePosTap(onTap, false);
      return <button type="button" data-testid="target" {...result.bind()} />;
    }

    render(<Probe />);

    const handlers = result?.bind() ?? {};
    handlers.onPointerDown?.({ pointerType: 'touch', preventDefault: () => {} });
    expect(onTap).not.toHaveBeenCalled();
  });

  it('deduplica disparos repetidos dentro de la ventana de supresion', () => {
    const onTap = vi.fn();
    let result!: ReturnType<typeof usePosTap>;

    function Probe() {
      result = usePosTap(onTap);
      return <button type="button" data-testid="target" {...result.bind()} />;
    }

    render(<Probe />);

    const handlers = result?.bind() ?? {};
    handlers.onPointerDown?.({ pointerType: 'touch', preventDefault: () => {} });
    result?.fire();
    result?.fire();

    expect(onTap).toHaveBeenCalledTimes(1);
  });
});
