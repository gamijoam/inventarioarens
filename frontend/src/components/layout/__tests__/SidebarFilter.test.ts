/**
 * Tests del sidebar filter de Organizaciones: la regla que decide si el
 * item "Organizaciones" debe mostrarse segun el estado del query
 * `useTenantGroups`.
 *
 * Se testea la logica pura (sin renderizar el Sidebar completo) para
 * evitar acoplamiento con router, permissions context, etc.
 */
import { describe, it, expect } from 'vitest';

type QueryState = {
  data: unknown[] | undefined;
  isLoading: boolean;
  isError: boolean;
};

/**
 * Reproduce exactamente la regla del filter en Sidebar.tsx para no
 * duplicar logica: si el query ya cargo sin error, solo mostrar si tengo
 * grupos; en cualquier otro caso (loading, error) mostrar siempre.
 */
function shouldShowOrganizaciones(query: QueryState): boolean {
  const ownedGroupIds = new Set(((query.data ?? []) as { id: number }[]).map((g) => g.id));
  const loadedOwnedGroups = !query.isLoading && !query.isError && query.data !== undefined;
  const shouldHideOrgItem = loadedOwnedGroups && ownedGroupIds.size === 0;
  return !shouldHideOrgItem;
}

describe('Sidebar filter - Organizaciones visibility', () => {
  it('muestra durante loading inicial (sin esperar al query)', () => {
    expect(
      shouldShowOrganizaciones({ data: undefined, isLoading: true, isError: false }),
    ).toBe(true);
  });

  it('muestra si el query fallo (error de red / 401)', () => {
    expect(
      shouldShowOrganizaciones({ data: undefined, isLoading: false, isError: true }),
    ).toBe(true);
  });

  it('muestra cuando el query completo retorna 1 grupo', () => {
    expect(
      shouldShowOrganizaciones({
        data: [{ id: 3 }],
        isLoading: false,
        isError: false,
      }),
    ).toBe(true);
  });

  it('muestra cuando el query completo retorna N grupos', () => {
    expect(
      shouldShowOrganizaciones({
        data: [{ id: 1 }, { id: 2 }, { id: 3 }],
        isLoading: false,
        isError: false,
      }),
    ).toBe(true);
  });

  it('oculta solo cuando el query completo confirma 0 grupos', () => {
    expect(
      shouldShowOrganizaciones({
        data: [],
        isLoading: false,
        isError: false,
      }),
    ).toBe(false);
  });

  it('muestra si todavia esta loading aunque el data este vacio', () => {
    // Caso edge: data es [] pero isLoading sigue true (refetch en background).
    // No debemos ocultar hasta que sepamos con certeza que no hay grupos.
    expect(
      shouldShowOrganizaciones({ data: [], isLoading: true, isError: false }),
    ).toBe(true);
  });

  it('muestra si data esta vacio pero hay error', () => {
    expect(
      shouldShowOrganizaciones({ data: [], isLoading: false, isError: true }),
    ).toBe(true);
  });
});