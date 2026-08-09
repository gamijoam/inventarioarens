import { act, render, screen } from '@testing-library/react';
import { useState } from 'react';
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
    handlers.onPointerDown?.({ pointerType: 'touch', preventDefault: vi.fn() });
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
    handlers.onPointerDown?.({ pointerType: 'mouse', preventDefault: vi.fn() });
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
    handlers.onPointerDown?.({ pointerType: 'touch', preventDefault: vi.fn() });
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
    handlers.onPointerDown?.({ pointerType: 'touch', preventDefault: vi.fn() });
    result?.fire();
    result?.fire();

    expect(onTap).toHaveBeenCalledTimes(1);
  });

  it('mantiene la deduplicacion aunque el toque provoque un render', () => {
    const onTap = vi.fn();
    let result!: ReturnType<typeof usePosTap>;

    function Probe() {
      const [, setVersion] = useState(0);
      result = usePosTap(() => {
        onTap();
        setVersion((version) => version + 1);
      });
      return <button type="button" data-testid="target" {...result.bind()} />;
    }

    render(<Probe />);

    act(() => {
      result.bind().onPointerDown?.({ pointerType: 'touch', preventDefault: vi.fn() });
    });
    act(() => {
      result.fire();
    });

    expect(onTap).toHaveBeenCalledTimes(1);
  });
});
