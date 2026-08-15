import { describe, expect, it } from 'vitest';

import {
  localWorkerLabel,
  normalizeSelectedTenantIds,
  type LocalWorkerStatus,
} from '@/features/local-support/api';

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

describe('normalizeSelectedTenantIds', () => {
  it('elimina duplicados y valores invalidos antes de enviarlos al bootstrap', () => {
    expect(normalizeSelectedTenantIds([5, 5, 0, -2, 3.5, 2])).toEqual([5, 2]);
  });
});
