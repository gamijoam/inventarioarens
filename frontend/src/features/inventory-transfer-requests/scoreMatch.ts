/**
 * scoreMatch: scoring puro para ordenar productos del catalogo destino
 * contra el producto origen de una solicitud inter-empresa.
 *
 * Score:
 *  - 100 + 'sku'      : SKU exacto (case-insensitive, trim).
 *  - 90  + 'barcode'  : Barcode exacto.
 *  - 60  + 'name'     : Nombre contiene al origen o viceversa.
 *  - 0   + 'none'     : Sin match.
 *
 * Sin dependencias externas (no React, no hooks). Exportado para tests
 * unitarios y para reuso en otros dialogs que necesiten el mismo matching
 * (ej. crear producto desde catalogo maestro).
 */

export type MatchType = 'sku' | 'barcode' | 'name' | 'none';

export interface ProductLiteForMatch {
  sku?: string | null;
  barcode?: string | null;
  name: string;
}

export interface MatchResult {
  score: number;
  matchType: MatchType;
}

function norm(value: string | null | undefined): string {
  return (value ?? '').trim().toLowerCase();
}

export function scoreMatch(
  origin: ProductLiteForMatch | null | undefined,
  destination: ProductLiteForMatch,
): MatchResult {
  if (!origin) return { score: 0, matchType: 'none' };

  const oSku = norm(origin.sku);
  const dSku = norm(destination.sku);
  if (oSku && dSku && oSku === dSku) {
    return { score: 100, matchType: 'sku' };
  }

  const oBarcode = norm(origin.barcode);
  const dBarcode = norm(destination.barcode);
  if (oBarcode && dBarcode && oBarcode === dBarcode) {
    return { score: 90, matchType: 'barcode' };
  }

  const oName = norm(origin.name);
  const dName = norm(destination.name);
  if (oName && dName && (dName.includes(oName) || oName.includes(dName))) {
    return { score: 60, matchType: 'name' };
  }

  return { score: 0, matchType: 'none' };
}

/**
 * Compara dos MatchResult para ordenar de mayor a menor score. Si empate,
 * mantiene el orden estable (devuelve 0).
 */
export function compareMatches(a: MatchResult, b: MatchResult): number {
  return b.score - a.score;
}
