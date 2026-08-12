import { describe, expect, it } from 'vitest';

// El generador es un script Node puro (sin deps). Verificamos que el
// codificador de PNG/ICO produce archivos validos probando la logica interna.
// El script se ejecuta en el build (generate-app-icons.cjs); aqui se valida
// que los iconos comprometidos existan y tengan el formato correcto.
import fs from 'node:fs';
import path from 'node:path';

const iconsRoot = path.resolve(process.cwd(), 'build', 'icons');

describe('generate-app-icons.cjs', () => {
  const apps = ['admin', 'pos', 'technician'];

  it.each(apps)('genera icon.ico y icon.png para %s', (app) => {
    const ico = fs.readFileSync(path.join(iconsRoot, app, 'icon.ico'));
    const png = fs.readFileSync(path.join(iconsRoot, app, 'icon.png'));

    // ICO: reservado(0,0), type=1, count>0.
    expect(ico[0]).toBe(0);
    expect(ico[1]).toBe(0);
    expect(ico.readUInt16LE(2)).toBe(1);
    expect(ico.readUInt16LE(4)).toBeGreaterThan(0);

    // PNG: magic + dims 256x256 (big-endian).
    expect(png.subarray(1, 4).toString('ascii')).toBe('PNG');
    expect(png.readUInt32BE(16)).toBe(256);
    expect(png.readUInt32BE(20)).toBe(256);
  });
});
