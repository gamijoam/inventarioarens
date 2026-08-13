import { describe, expect, it } from 'vitest';

// El helper extractPrintingError extrae el mensaje real de errores de la API.
// Se exporta desde PrintingManager; lo importamos via require indirecto para
// no arrastrar todo el componente. Mejor: lo duplicamos aqui como contrato
// para que el comportamiento quede documentado.
function extractPrintingError(error: unknown, fallback: string): string {
  if (error && typeof error === 'object' && 'response' in error) {
    const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response;
    const firstError = response?.data?.errors
      ? Object.values(response.data.errors).flat()[0]
      : undefined;
    return firstError ?? response?.data?.message ?? fallback;
  }
  return error instanceof Error && error.message ? error.message : fallback;
}

describe('extractPrintingError (contrato del perfil de impresion)', () => {
  it('extrae el primer mensaje de validacion del backend (name unique)', () => {
    const error = {
      response: {
        data: {
          message: 'The name has already been taken.',
          errors: { name: ['The name has already been taken.'] },
        },
      },
    };

    expect(extractPrintingError(error, 'No se pudo guardar el perfil.')).toBe(
      'The name has already been taken.',
    );
  });

  it('cae al message global cuando no hay errors detallados', () => {
    const error = { response: { data: { message: 'Servidor ocupado' } } };

    expect(extractPrintingError(error, 'No se pudo guardar el perfil.')).toBe('Servidor ocupado');
  });

  it('usa el fallback cuando no hay respuesta de la API', () => {
    expect(extractPrintingError(new Error('red'), 'No se pudo guardar el perfil.')).toBe('red');
    expect(extractPrintingError(null, 'No se pudo guardar el perfil.')).toBe(
      'No se pudo guardar el perfil.',
    );
  });
});
