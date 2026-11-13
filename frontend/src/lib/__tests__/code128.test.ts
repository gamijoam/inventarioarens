import { describe, expect, it } from 'vitest';

import { CODE128_PATTERNS, code128Bars, code128Values } from '../code128';

describe('code128', () => {
  it('cada simbolo mide 11 modulos y el de parada 13', () => {
    expect(CODE128_PATTERNS).toHaveLength(107);
    CODE128_PATTERNS.slice(0, 106).forEach((pattern) => {
      expect([...pattern].reduce((sum, w) => sum + Number(w), 0)).toBe(11);
    });
    expect([...(CODE128_PATTERNS[106] ?? '')].reduce((sum, w) => sum + Number(w), 0)).toBe(13);
  });

  it('usa el juego B para texto y calcula el digito de control', () => {
    // inicio B (104) + P(48)*1 + J(42)*2 + J*3 + 1(17)*4 + 2(18)*5 + 3(19)*6 + C(35)*7 = 879; 879 % 103 = 55
    expect(code128Values('PJJ123C')).toEqual([104, 48, 42, 42, 17, 18, 19, 35, 55, 106]);
  });

  it('usa el juego C para numeros de longitud par', () => {
    // inicio C (105) + 75*1 + 90*2 + 1*3 = 363; 363 % 103 = 54
    expect(code128Values('759001')).toEqual([105, 75, 90, 1, 54, 106]);
  });

  it('un numero de longitud impar codifica el primer digito en B y el resto en C', () => {
    // inicio B (104) + '7'(23)*1 + cambio a C (99)*2 + 59*3 + 0*4 + 1*5 ... 
    const values = code128Values('7590001');
    expect(values.slice(0, 3)).toEqual([104, 23, 99]);
    expect(values.slice(3, 6)).toEqual([59, 0, 1]);
    const checksum = (104 + 23 * 1 + 99 * 2 + 59 * 3 + 0 * 4 + 1 * 5) % 103;
    expect(values.slice(6)).toEqual([checksum, 106]);
  });

  it('las barras alternan barra y espacio y suman el ancho total', () => {
    const bars = code128Bars('ABC');
    expect(bars[0]?.dark).toBe(true);
    expect(bars[1]?.dark).toBe(false);
    const total = bars.reduce((sum, bar) => sum + bar.width, 0);
    expect(total).toBe(11 * 5 + 13);
  });

  it('rechaza caracteres fuera de ASCII imprimible', () => {
    expect(() => code128Values('Ñandu')).toThrow();
    expect(() => code128Values('')).toThrow();
  });
});
