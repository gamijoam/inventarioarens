import { describe, expect, it } from 'vitest';

import verifier from '../../scripts/verify-electron-artifact.cjs';

describe('Electron artifact verification', () => {
  it('accepts an artifact containing only its client renderer', () => {
    expect(
      verifier.validateEntries('admin', [
        'electron/main.cjs',
        'dist/admin/index.html',
        'dist/admin/assets/index.js',
        'package.json',
      ]),
    ).toEqual({ client: 'admin', renderer: 'dist/admin' });
  });

  it('rejects foreign client renderers', () => {
    expect(() =>
      verifier.validateEntries('pos', [
        'electron/main.cjs',
        'dist/pos/index.html',
        'dist/admin/index.html',
        'package.json',
      ]),
    ).toThrow('dist/admin');
  });

  it('rejects Motor Local payloads inside a client artifact', () => {
    expect(() =>
      verifier.validateEntries('technician', [
        'electron/main.cjs',
        'dist/technician/index.html',
        'dist/technician/assets/index.js',
        'php/php.exe',
        'data/inventario.sqlite',
        'package.json',
      ]),
    ).toThrow('Motor Local');
  });
});
