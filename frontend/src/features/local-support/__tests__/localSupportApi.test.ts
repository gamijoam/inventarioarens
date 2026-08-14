import { describe, expect, it } from 'vitest';

import { localWorkerLabel, type LocalWorkerStatus } from '@/features/local-support/api';

function worker(overrides: Partial<LocalWorkerStatus> = {}): LocalWorkerStatus {
  return {
    available: true,
    active: true,
    pid: null,
    message: '',
    ...overrides,
  };
}

describe('localWorkerLabel', () => {
  it('identifica el supervisor central del Motor Local', () => {
    expect(localWorkerLabel(worker({ service: 'SistemaInventarioSync' }))).toBe(
      'Motor Local activo',
    );
    expect(localWorkerLabel(worker({ active: false, service: 'SistemaInventarioSync' }))).toBe(
      'Motor Local detenido',
    );
  });

  it('conserva la etiqueta del worker legado fuera del Motor Local', () => {
    expect(localWorkerLabel(worker())).toBe('Worker activo');
    expect(localWorkerLabel(worker({ active: false }))).toBe('Worker detenido');
  });
});
