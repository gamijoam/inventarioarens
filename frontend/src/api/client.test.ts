import { describe, expect, it } from 'vitest';

import { resolveLocalApiBaseUrl } from './client';

describe('resolveLocalApiBaseUrl', () => {
  it('mantiene localhost para que la cookie de sesión comparta el mismo host', () => {
    expect(resolveLocalApiBaseUrl('localhost')).toBe('http://localhost:8787/api');
  });

  it('mantiene 127.0.0.1 cuando la aplicación fue abierta con esa dirección', () => {
    expect(resolveLocalApiBaseUrl('127.0.0.1')).toBe('http://127.0.0.1:8787/api');
  });
});
