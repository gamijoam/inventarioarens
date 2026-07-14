import { describe, it, expect } from 'vitest';
import { cn } from './cn';

describe('cn()', () => {
  it('combina classnames simples', () => {
    expect(cn('foo', 'bar')).toBe('foo bar');
  });

  it('ignora valores falsy', () => {
    const includeBar = false;
    expect(cn('foo', includeBar && 'bar', null, undefined, 'baz')).toBe('foo baz');
  });

  it('mergea conflictos de Tailwind', () => {
    expect(cn('px-2', 'px-4')).toBe('px-4');
  });

  it('acepta arrays y objetos', () => {
    expect(cn(['foo', 'bar'], { baz: true, qux: false })).toBe('foo bar baz');
  });
});