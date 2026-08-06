import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { describe, expect, it } from 'vitest';

import rendererServer from './renderer-server.cjs';

const { contentTypeFor, safeFilePath, startRendererServer } = rendererServer;

describe('Electron renderer server', () => {
  it('resolves files inside the renderer root without allowing traversal', () => {
    expect(safeFilePath('/bundle/dist/admin', '/assets/app.js')).toBe(
      '/bundle/dist/admin/assets/app.js',
    );
    expect(safeFilePath('/bundle/dist/admin', '/../package.json')).toBeNull();
  });

  it('returns useful content types for packaged assets', () => {
    expect(contentTypeFor('index.html')).toBe('text/html; charset=utf-8');
    expect(contentTypeFor('assets/app.js')).toBe('text/javascript; charset=utf-8');
    expect(contentTypeFor('assets/app.css')).toBe('text/css; charset=utf-8');
  });

  it('listens on the requested stable port', async () => {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'inventario-renderer-'));
    fs.writeFileSync(path.join(root, 'index.html'), '<!doctype html>');
    const renderer = await startRendererServer(root, { port: 0 });

    expect(new URL(renderer.url).hostname).toBe('127.0.0.1');
    expect(Number(new URL(renderer.url).port)).toBeGreaterThan(0);

    await new Promise((resolve) => renderer.server.close(resolve));
    fs.rmSync(root, { recursive: true, force: true });
  });
});
