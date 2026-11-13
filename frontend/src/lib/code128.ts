/**
 * Codificador Code 128 (juegos B y C) para imprimir codigos de barras en
 * etiquetas desde el navegador, sin dependencias externas.
 */
export const CODE128_PATTERNS: readonly string[] = [
  '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
  '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
  '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
  '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
  '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
  '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
  '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
  '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
  '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
  '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
  '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
];

const START_B = 104;
const START_C = 105;
const CODE_C = 99;
const STOP = 106;

function isDigits(text: string): boolean {
  return /^\d+$/.test(text);
}

function codeB(char: string): number {
  const code = char.charCodeAt(0);
  if (code < 32 || code > 127) {
    throw new Error(`Caracter no soportado en Code 128: ${char}`);
  }
  return code - 32;
}

export function code128Values(text: string): number[] {
  if (text.length === 0) {
    throw new Error('El codigo de barras esta vacio.');
  }

  const values: number[] = [];
  if (isDigits(text) && text.length >= 2) {
    let rest = text;
    if (text.length % 2 === 1) {
      values.push(START_B, codeB(text.charAt(0)), CODE_C);
      rest = text.slice(1);
    } else {
      values.push(START_C);
    }
    for (let i = 0; i < rest.length; i += 2) {
      values.push(Number(rest.slice(i, i + 2)));
    }
  } else {
    values.push(START_B, ...[...text].map(codeB));
  }

  const checksum = values.reduce((sum, value, index) => sum + value * (index === 0 ? 1 : index), 0) % 103;
  return [...values, checksum, STOP];
}

export interface Code128Bar {
  dark: boolean;
  width: number;
}

export function code128Bars(text: string): Code128Bar[] {
  return code128Values(text).flatMap((value) =>
    [...(CODE128_PATTERNS[value] ?? '')].map((width, index) => ({ dark: index % 2 === 0, width: Number(width) })),
  );
}
