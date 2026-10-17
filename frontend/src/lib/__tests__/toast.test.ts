import { beforeEach, describe, expect, it, vi } from 'vitest';

const sonnerMock = vi.hoisted(() => {
  const callable = vi.fn();
  return {
    toast: Object.assign(callable, {
      success: vi.fn(),
      info: vi.fn(),
      warning: vi.fn(),
      error: vi.fn(),
      custom: vi.fn(),
      message: vi.fn(),
      promise: vi.fn(),
      dismiss: vi.fn(),
      loading: vi.fn(),
      getHistory: vi.fn(() => []),
      getToasts: vi.fn(() => []),
    }),
  };
});

vi.mock('sonner', () => sonnerMock);

import { localizeApiErrorMessage, toast } from '../toast';

describe('avisos de error', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it.each([
    [401, 'Unauthenticated.', 'Tu sesión expiró. Vuelve a iniciar sesión.'],
    [403, 'This action is unauthorized.', 'No tienes permiso para esta acción.'],
    [404, 'The requested resource was not found.', 'Recurso no encontrado.'],
    [500, 'Internal Server Error', 'Error del servidor. Por favor intenta de nuevo.'],
    [502, 'Bad Gateway', 'Error del servidor. Por favor intenta de nuevo.'],
    [503, 'Service Unavailable', 'Error del servidor. Por favor intenta de nuevo.'],
    [0, 'Network Error', 'Error de red. Verifica tu conexión.'],
  ])('localiza respuestas %s sin cambiar mensajes ya escritos en español', (status, source, expected) => {
    expect(localizeApiErrorMessage(source, status)).toBe(expected);
  });

  it('conserva el mensaje específico de validación y traduce el genérico de Laravel', () => {
    expect(localizeApiErrorMessage('The given data was invalid.', 422))
      .toBe('Los datos ingresados no son válidos.');
    expect(localizeApiErrorMessage('El precio debe ser mayor que cero.', 422))
      .toBe('El precio debe ser mayor que cero.');
  });

  it('usa el mismo identificador para el interceptor y el catch local del mismo error', () => {
    toast.error('Network Error');
    toast.error('Error de red. Verifica tu conexión.');

    expect(sonnerMock.toast.error).toHaveBeenCalledTimes(2);
    const first = sonnerMock.toast.error.mock.calls[0]!;
    const second = sonnerMock.toast.error.mock.calls[1]!;
    expect(first[0]).toBe('Error de red. Verifica tu conexión.');
    expect(second[0]).toBe(first[0]);
    expect(second[1].id).toBe(first[1].id);
  });
});
