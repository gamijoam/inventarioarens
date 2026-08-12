/**
 * Genera iconos de aplicacion (ICO + PNG) para los tres clientes Electron.
 *
 * Sin dependencias externas: dibuja un logo simple (fondo redondeado + rayas
 * de inventario) en pixel buffer, lo codifica a PNG (zlib) y lo encapsula en
 * un ICO multi-resolucion (Windows Vista+ / electron-builder).
 *
 * Uso: node scripts/generate-app-icons.cjs
 * Salida:
 *   frontend/build/icons/admin/icon.ico  + icon.png
 *   frontend/build/icons/pos/icon.ico    + icon.png
 *   frontend/build/icons/technician/icon.ico + icon.png
 */
const fs = require('node:fs');
const path = require('node:path');
const zlib = require('node:zlib');

const ROOT = path.resolve(__dirname, '..', 'frontend');
const OUT = path.join(ROOT, 'build', 'icons');

// Paleta por cliente: color principal + acento.
const APPS = [
  { id: 'admin', primary: [37, 99, 235], accent: [250, 204, 21] }, // azul + amarillo
  { id: 'pos', primary: [16, 185, 129], accent: [255, 255, 255] }, // verde + blanco
  { id: 'technician', primary: [100, 116, 139], accent: [226, 232, 240] }, // gris pizarra
];

const SIZES = [16, 24, 32, 48, 64, 128, 256];

function roundedRect(x, y, w, h, r, px, py) {
  // Devuelve true si (px,py) esta dentro del rectangulo con esquinas redondeadas.
  const cx = Math.min(Math.max(px, x + r), x + w - r);
  const cy = Math.min(Math.max(py, y + r), y + h - r);
  const dx = px - cx;
  const dy = py - cy;
  return dx * dx + dy * dy <= r * r;
}

function renderPixels(size, [pr, pg, pb], [ar, ag, ab]) {
  const pixels = Buffer.alloc(size * size * 4);
  const margin = Math.max(1, Math.round(size * 0.08));
  const body = size - margin * 2;
  const radius = Math.max(1, Math.round(size * 0.22));
  const lineH = Math.max(1, Math.round(size * 0.09));
  const gap = Math.max(1, Math.round(size * 0.16));
  const stripeW = Math.round(size * 0.5);
  const dotR = Math.max(1, Math.round(size * 0.09));

  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const idx = (y * size + x) * 4;
      let r = 0;
      let g = 0;
      let b = 0;
      let a = 0;

      if (roundedRect(margin, margin, body, body, radius, x + 0.5, y + 0.5)) {
        r = pr;
        g = pg;
        b = pb;
        a = 255;
      }

      if (a === 255) {
        const lineY = margin + lineH + (y >= margin + lineH && y < margin + lineH * 2 + gap ? 0 : 0);
        // Dos rayas de inventario + dos puntos de acento.
        const stripeTop = margin + Math.round(body * 0.22);
        const stripeBottom = margin + Math.round(body * 0.78);
        const stripeLeft = margin + Math.round(body * 0.16);
        const inStripe1 = y >= stripeTop && y < stripeTop + lineH && x >= stripeLeft && x < stripeLeft + stripeW;
        const inStripe2 = y >= stripeBottom - lineH && y < stripeBottom && x >= stripeLeft && x < stripeLeft + stripeW;
        const dx1 = x - (stripeLeft + stripeW + gap);
        const dy1 = y - (stripeTop + lineH / 2);
        const dx2 = x - (stripeLeft + stripeW / 2);
        const dy2 = y - (stripeBottom - lineH / 2);
        const inDot1 = dx1 * dx1 + dy1 * dy1 <= dotR * dotR;
        const inDot2 = dx2 * dx2 + dy2 * dy2 <= dotR * dotR;

        if (inStripe1 || inStripe2 || inDot1 || inDot2) {
          r = ar;
          g = ag;
          b = ab;
        }
      }

      pixels[idx] = r;
      pixels[idx + 1] = g;
      pixels[idx + 2] = b;
      pixels[idx + 3] = a;
    }
  }

  return pixels;
}

function crc32(buf) {
  let c;
  const table = crc32.table || (crc32.table = (() => {
    const t = [];
    for (let n = 0; n < 256; n++) {
      c = n;
      for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
      t[n] = c >>> 0;
    }
    return t;
  })());
  let crc = 0xffffffff;
  for (let i = 0; i < buf.length; i++) crc = table[(crc ^ buf[i]) & 0xff] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}

function pngChunk(type, data) {
  const len = Buffer.alloc(4);
  len.writeUInt32BE(data.length, 0);
  const typeBuf = Buffer.from(type, 'ascii');
  const crcBuf = Buffer.alloc(4);
  crcBuf.writeUInt32BE(crc32(Buffer.concat([typeBuf, data])), 0);
  return Buffer.concat([len, typeBuf, data, crcBuf]);
}

function encodePng(size, pixels) {
  const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(size, 0);
  ihdr.writeUInt32BE(size, 4);
  ihdr[8] = 8; // bit depth
  ihdr[9] = 6; // color type RGBA
  const raw = Buffer.alloc((size * 4 + 1) * size);
  for (let y = 0; y < size; y++) {
    raw[y * (size * 4 + 1)] = 0; // filter none
    pixels.copy(raw, y * (size * 4 + 1) + 1, y * size * 4, (y + 1) * size * 4);
  }
  const idat = zlib.deflateSync(raw, { level: 9 });
  return Buffer.concat([
    sig,
    pngChunk('IHDR', ihdr),
    pngChunk('IDAT', idat),
    pngChunk('IEND', Buffer.alloc(0)),
  ]);
}

function buildIco(pngs) {
  // pngs: array de { size, data }
  const header = Buffer.alloc(6);
  header.writeUInt16LE(0, 0);
  header.writeUInt16LE(1, 2); // type ICO
  header.writeUInt16LE(pngs.length, 4);
  const entries = [];
  const payloads = [];
  let offset = 6 + pngs.length * 16;
  for (const { size, data } of pngs) {
    const entry = Buffer.alloc(16);
    entry[0] = size >= 256 ? 0 : size;
    entry[1] = size >= 256 ? 0 : size;
    entry[2] = 0;
    entry[3] = 0;
    entry.writeUInt16LE(1, 4); // planes
    entry.writeUInt16LE(32, 6); // bpp
    entry.writeUInt32LE(data.length, 8);
    entry.writeUInt32LE(offset, 12);
    entries.push(entry);
    payloads.push(data);
    offset += data.length;
  }
  return Buffer.concat([header, ...entries, ...payloads]);
}

function main() {
  fs.mkdirSync(OUT, { recursive: true });
  for (const app of APPS) {
    const dir = path.join(OUT, app.id);
    fs.mkdirSync(dir, { recursive: true });
    const pngs = [];
    for (const size of SIZES) {
      const pixels = renderPixels(size, app.primary, app.accent);
      const data = encodePng(size, pixels);
      pngs.push({ size, data });
      if (size === 256) {
        fs.writeFileSync(path.join(dir, 'icon.png'), data);
      }
    }
    fs.writeFileSync(path.join(dir, 'icon.ico'), buildIco(pngs));
    console.log(`Generado ${path.relative(ROOT, path.join(dir, 'icon.ico'))} (${pngs.length} tamaños)`);
  }
}

main();
